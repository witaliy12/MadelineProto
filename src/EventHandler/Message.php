<?php declare(strict_types=1);

namespace danog\MadelineProto\EventHandler;

use danog\MadelineProto\EventHandler\Keyboard\InlineKeyboard;
use danog\MadelineProto\EventHandler\Keyboard\ReplyKeyboard;
use danog\MadelineProto\EventHandler\Media\Audio;
use danog\MadelineProto\EventHandler\Media\Document;
use danog\MadelineProto\EventHandler\Media\DocumentPhoto;
use danog\MadelineProto\EventHandler\Media\Gif;
use danog\MadelineProto\EventHandler\Media\MaskSticker;
use danog\MadelineProto\EventHandler\Media\Photo;
use danog\MadelineProto\EventHandler\Media\RoundVideo;
use danog\MadelineProto\EventHandler\Media\Sticker;
use danog\MadelineProto\EventHandler\Media\Video;
use danog\MadelineProto\EventHandler\Media\Voice;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\StrTools;

/**
 * Represents an incoming or outgoing message.
 */
abstract class Message extends AbstractMessage
{
    /** Content of the message */
    public readonly string $message;

    private bool $reactionsCached = false;


    /** @var list<int|string> list of our message reactions */
    private ?array $reactions;

    /** Info about a forwarded message */
    public readonly ?ForwardedInfo $fwdInfo;

    /** Bot command (if present) */
    public readonly ?string $command;
    /** Bot command type (if present) */
    public readonly ?CommandType $commandType;
    /** @var list<string> Bot command arguments (if present) */
    public readonly ?array $commandArgs;

    /** Whether this message is protected */
    public readonly bool $protected;

    /**
     * @readonly
     *
     * @var list<string> Regex matches, if a filter regex is present
     */
    public ?array $matches = null;

    /**
     * Attached media.
     */
    public readonly Audio|Document|DocumentPhoto|Gif|MaskSticker|Photo|RoundVideo|Sticker|Video|Voice|null $media;

    /** Whether this message is a sent scheduled message */
    public readonly bool $fromScheduled;

    /** If the message was generated by an inline query, ID of the bot that generated it */
    public readonly ?int $viaBotId;

    /** Last edit date of the message */
    public readonly ?int $editDate;

    /** Inline or reply keyboard. */
    public readonly InlineKeyboard|ReplyKeyboard|null $keyboard;

    /** Whether this message was [imported from a foreign chat service](https://core.telegram.org/api/import) */
    public readonly bool $imported;

    /** For Public Service Announcement messages, the PSA type */
    public readonly ?string $psaType;

    /** @readonly For sent messages, contains the next message in the chain if the original message had to be split. */
    public ?self $nextSent = null;
    // Todo media (photosizes, thumbs), albums, reactions, games eventually

    /** @internal */
    public function __construct(
        MTProto $API,
        array $rawMessage,
        array $info,
    ) {
        parent::__construct($API, $rawMessage, $info);

        $this->entities = $rawMessage['entities'] ?? null;
        $this->message = $rawMessage['message'];
        $this->fromScheduled = $rawMessage['from_scheduled'];
        $this->viaBotId = $rawMessage['via_bot_id'] ?? null;
        $this->editDate = $rawMessage['edit_date'] ?? null;

        $this->keyboard = isset($rawMessage['reply_markup'])
            ? Keyboard::fromRawReplyMarkup($rawMessage['reply_markup'])
            : null;

        if (isset($rawMessage['fwd_from'])) {
            $fwdFrom = $rawMessage['fwd_from'];
            $this->fwdInfo = new ForwardedInfo(
                $fwdFrom['date'],
                isset($fwdFrom['from_id'])
                    ? $this->getClient()->getIdInternal($fwdFrom['from_id'])
                    : null,
                $fwdFrom['from_name'] ?? null,
                $fwdFrom['channel_post'] ?? null,
                $fwdFrom['post_author'] ?? null,
                isset($fwdFrom['saved_from_peer'])
                    ? $this->getClient()->getIdInternal($fwdFrom['saved_from_peer'])
                    : null,
                $fwdFrom['saved_from_msg_id'] ?? null
            );
            $this->psaType = $fwdFrom['psa_type'] ?? null;
        } else {
            $this->fwdInfo = null;
            $this->psaType = null;
        }

        $this->protected = $rawMessage['noforwards'];

        $this->media = isset($rawMessage['media'])
            ? $API->wrapMedia($rawMessage['media'], $this->protected)
            : null;

        if (\in_array($this->message[0] ?? '', ['/', '.', '!'], true)) {
            $space = \strpos($this->message, ' ', 1) ?: \strlen($this->message);
            $this->command = \substr($this->message, 1, $space-1);
            $args = \explode(
                ' ',
                \substr($this->message, $space+1)
            );
            $this->commandArgs = $args === [''] ? [] : $args;
            $this->commandType = match ($this->message[0]) {
                '.' => CommandType::DOT,
                '/' => CommandType::SLASH,
                '!' => CommandType::BANG,
            };
        } else {
            $this->command = null;
            $this->commandArgs = null;
            $this->commandType = null;
        }
    }

    /**
     * Pin a message
     *
     * @param bool $pmOneside Whether the message should only be pinned on the local side of a one-to-one chat
     * @param bool $silent Pin the message silently, without triggering a notification
     * @return AbstractMessage|null
     */
    public function pin(bool $pmOneside = false,bool $silent = false) : ?AbstractMessage
    {
        $result = $this->getClient()->methodCallAsyncRead(
            'messages.updatePinnedMessage',
            [
                'peer' => $this->chatId,
                'id' => $this->id,
                'pm_oneside' => $pmOneside,
                'silent' => $silent,
                'unpin' => false
            ]
        );
        return $this->getClient()->wrapMessage($this->getClient()->extractMessage($result));
    }

    /**
     * Unpin a message
     *
     * @param bool $pmOneside Whether the message should only be pinned on the local side of a one-to-one chat
     * @param bool $silent Pin the message silently, without triggering a notification
     * @return Update|null
     */
    public function unpin(bool $pmOneside = false,bool $silent = false) : ?Update
    {
        $result = $this->getClient()->methodCallAsyncRead(
            'messages.updatePinnedMessage',
            [
                'peer' => $this->chatId,
                'id' => $this->id,
                'pm_oneside' => $pmOneside,
                'silent' => $silent,
                'unpin' => true
            ]
        );
        return $this->getClient()->wrapUpdate($result);
    }

    /**
     * Get our reaction on message return null if message deleted
     *
     * @return list<string|int>|null
     */
    public function getReactions(): ?array
    {
        if(!$this->reactionsCached){
            $this->reactionsCached = true;
            $me = $this->getClient()->getSelf()['id'];
            $myReactions = array_filter(
                $this->getClient()->methodCallAsyncRead(
                    'messages.getMessageReactionsList',
                    [
                        'peer' => $this->chatId,
                        'id' => $this->id
                    ]
                )['reactions'],
                fn($reactions):bool => $reactions['peer_id']['user_id'] ?? $reactions['peer_id']['channel_id'] == $me
            );
            $this->reactions = array_map(fn($reaction) => $reaction['reaction']['emoticon'] ?? $reaction['reaction']['document_id'] ,$myReactions);
        }
        return $this->reactions;
    }

    /**
     * Add reaction to message
     *
     * @param list<string|int> $reaction Array of Reaction
     * @param bool $big Whether a bigger and longer reaction should be shown
     * @param bool $addToRecent Add this reaction to the recent reactions list.
     * @return Update|null
     */
    public function addReaction(array $reaction,bool $big = false,bool $addToRecent = true) :?Update{
        $result = $this->getClient()->methodCallAsyncRead(
            'messages.sendReaction',
            [
                'peer' => $this->chatId,
                'msg_id' => $this->id,
                'reaction' => array_map(fn($reactions) => is_int($reactions) ? ['_' => 'reactionCustomEmoji', 'document_id' => $reactions] : ['_' => 'reactionEmoji', 'emoticon' => $reactions],$reaction),
                'big' => $big,
                'add_to_recent' => $addToRecent
            ]
        );
        $this->reactions += $reaction;
        return $this->getClient()->wrapUpdate($result);
    }

    /**
     * Delete reaction from message
     *
     * @param string|int $reaction string or int Reaction
     * @return Update|null
     */
    public function delReaction(int|string $reaction): ?Update
    {
        $result = $this->getClient()->methodCallAsyncRead(
            'messages.sendReaction',
            [
                'peer' => $this->chatId,
                'msg_id' => $this->id,
                'reaction' => [is_int($reaction) ? ['_' => 'reactionCustomEmoji', 'document_id' => $reaction] : ['_' => 'reactionEmoji', 'emoticon' => $reaction]],
            ]
        );
        unset($this->reactions[$reaction]);
        return $this->getClient()->wrapUpdate($result);
    }

    private readonly string $html;
    private readonly string $htmlTelegram;
    private readonly ?array $entities;

    /**
     * Get an HTML version of the message.
     *
     * @param bool $allowTelegramTags Whether to allow telegram-specific tags like tg-spoiler, tg-emoji, mention links and so on...
     */
    public function getHTML(bool $allowTelegramTags = false): string
    {
        if (!$this->entities) {
            return \htmlentities($this->message);
        }
        if ($allowTelegramTags) {
            return $this->htmlTelegram ??= StrTools::entitiesToHtml($this->message, $this->entities, $allowTelegramTags);
        }
        return $this->html ??= StrTools::entitiesToHtml($this->message, $this->entities, $allowTelegramTags);
    }
}
