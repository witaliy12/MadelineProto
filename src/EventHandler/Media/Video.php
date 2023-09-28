<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\EventHandler\Media;

use danog\MadelineProto\MTProto;
use danog\MadelineProto\TL\Types\Bytes;

/**
 * Represents a video.
 */
final class Video extends AbstractVideo
{
    /** If true; the current media has attached mask stickers. */
    public readonly bool $hasStickers;
    /** Content of thumbnail file (JPEGfile, quality 55, set in a square 90x90) only for secret chats. */
    public readonly ?Bytes $thumb;
    /** Thumbnail height only for secret chats. */
    public readonly ?int $thumbHeight;
    /** Thumbnail width only for secret chats. */
    public readonly ?int $thumbWidth;

    /** @internal */
    public function __construct(
        MTProto $API,
        array $rawMedia,
        array $attribute,
        bool $protected,
    ) {
        parent::__construct($API, $rawMedia, $attribute, $protected);
        $hasStickers = false;
        foreach ($rawMedia['document']['attributes'] ?? [] as ['_' => $t]) {
            if ($t === 'documentAttributeHasStickers') {
                $hasStickers = true;
                break;
            }
        }
        $this->hasStickers = $hasStickers;
        $this->thumb = isset($rawMedia['thumb']) ? new Bytes($rawMedia['thumb']) : null;
        $this->thumbHeight = $rawMedia['thumb_h'] ?? null;
        $this->thumbWidth = $rawMedia['thumb_w'] ?? null;
    }
}
