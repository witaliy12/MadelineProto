<?php declare(strict_types=1);
/**
 * This file is automatic generated by build_docs.php file
 * and is used only for autocomplete in multiple IDE
 * don't modify manually.
 */

namespace danog\MadelineProto\Namespace;

interface Auth
{
    /**
     * Terminates all user's authorized sessions except for the current one.
     *
     * After calling this method it is necessary to reregister the current device using the method [account.registerDevice](https://docs.madelineproto.xyz/API_docs/methods/account.registerDevice.html)
     *
     * @return array
     */
    public function resetAuthorizations();

    /**
     * Request recovery code of a [2FA password](https://core.telegram.org/api/srp), only for accounts with a [recovery email configured](https://core.telegram.org/api/srp#email-verification).
     *
     * @return array
     */
    public function requestPasswordRecovery();

    /**
     * Reset the [2FA password](https://core.telegram.org/api/srp) using the recovery code sent using [auth.requestPasswordRecovery](https://docs.madelineproto.xyz/API_docs/methods/auth.requestPasswordRecovery.html).
     *
     * @param string $code Code received via email
     * @param array $new_settings New password @see https://docs.madelineproto.xyz/API_docs/types/array.html
     *
     *
     * @return array
     */
    public function recoverPassword(string $code, array $new_settings = []);

    /**
     * Resend the login code via another medium, the phone code type is determined by the return value of the previous auth.sendCode/auth.resendCode: see [login](https://core.telegram.org/api/auth) for more info.
     *
     * @param string $phone_number The phone number
     * @param string $phone_code_hash The phone code hash obtained from [auth.sendCode](https://docs.madelineproto.xyz/API_docs/methods/auth.sendCode.html)
     *
     *
     * @return array
     */
    public function resendCode(string $phone_number, string $phone_code_hash);

    /**
     * Cancel the login verification code.
     *
     * @param string $phone_number Phone number
     * @param string $phone_code_hash Phone code hash from [auth.sendCode](https://docs.madelineproto.xyz/API_docs/methods/auth.sendCode.html)
     *
     *
     * @return array
     */
    public function cancelCode(string $phone_number, string $phone_code_hash);

    /**
     * Delete all temporary authorization keys **except for** the ones specified.
     *
     * @param array $except_auth_keys The auth keys that **shouldn't** be dropped. @see https://docs.madelineproto.xyz/API_docs/types/array.html
     *
     *
     * @return array
     */
    public function dropTempAuthKeys(array $except_auth_keys);

    /**
     * Generate a login token, for [login via QR code](https://core.telegram.org/api/qr-login).
     * The generated login token should be encoded using base64url, then shown as a `tg://login?token=base64encodedtoken` [deep link »](https://core.telegram.org/api/links#qr-code-login-links) in the QR code.
     *
     * For more info, see [login via QR code](https://core.telegram.org/api/qr-login).
     *
     * @param int $api_id Application identifier (see. [App configuration](https://core.telegram.org/myapp))
     * @param string $api_hash Application identifier hash (see. [App configuration](https://core.telegram.org/myapp))
     * @param array $except_ids List of already logged-in user IDs, to prevent logging in twice with the same user @see https://docs.madelineproto.xyz/API_docs/types/array.html
     *
     *
     * @return array
     */
    public function exportLoginToken(int $api_id, string $api_hash, array $except_ids);

    /**
     * Login using a redirected login token, generated in case of DC mismatch during [QR code login](https://core.telegram.org/api/qr-login).
     *
     * For more info, see [login via QR code](https://core.telegram.org/api/qr-login).
     *
     * @param string $token Login token
     *
     *
     * @return array
     */
    public function importLoginToken(string $token);

    /**
     * Accept QR code login token, logging in the app that generated it.
     *
     * Returns info about the new session.
     *
     * For more info, see [login via QR code](https://core.telegram.org/api/qr-login).
     *
     * @param string $token Login token embedded in QR code, for more info, see [login via QR code](https://core.telegram.org/api/qr-login).
     *
     *
     * @return array
     */
    public function acceptLoginToken(string $token);

    /**
     * Check if the [2FA recovery code](https://core.telegram.org/api/srp) sent using [auth.requestPasswordRecovery](https://docs.madelineproto.xyz/API_docs/methods/auth.requestPasswordRecovery.html) is valid, before passing it to [auth.recoverPassword](https://docs.madelineproto.xyz/API_docs/methods/auth.recoverPassword.html).
     *
     * @param string $code Code received via email
     *
     *
     * @return array
     */
    public function checkRecoveryPassword(string $code);

    /**
     *
     *
     *
     *
     * @return array
     */
    public function importWebTokenAuthorization(int $api_id, string $api_hash, string $web_auth_token);
}
