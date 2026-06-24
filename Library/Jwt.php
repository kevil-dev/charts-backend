<?php

namespace Library;

class Jwt {

    static private $secret = \JWT_SECRET;
    static private $jwt_algorith = 'HS256';
    static private $hash_algorith = 'sha256';

    public static function generate($data) {

        // Create the token header
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => self::$jwt_algorith
        ]);

        // Create the token payload
        $payload = json_encode($data);

        // Encode Header
        $base64UrlHeader = base64UrlEncode($header);

        // Encode Payload
        $base64UrlPayload = base64UrlEncode($payload);

        // Create Signature Hash
        $signature = hash_hmac(self::$hash_algorith, $base64UrlHeader . "." . $base64UrlPayload, self::$secret, true);

        // Encode Signature to Base64Url String
        $base64UrlSignature = base64UrlEncode($signature);

        // Create JWT
        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

        return $jwt;
    }

    public static function validate($jwt) {
        if (empty($jwt)) {
            return false;
        }

        $tokenParts = explode('.', $jwt);
        if (count($tokenParts) !== 3) {
            return false;
        }

        $header            = base64_decode($tokenParts[0]);
        $payload           = base64_decode($tokenParts[1]);
        $signatureProvided = $tokenParts[2];

        if ($header === false || $payload === false) {
            return false;
        }

        $base64UrlHeader    = base64UrlEncode($header);
        $base64UrlPayload   = base64UrlEncode($payload);
        $signature          = hash_hmac(self::$hash_algorith, $base64UrlHeader . "." . $base64UrlPayload, self::$secret, true);
        $base64UrlSignature = base64UrlEncode($signature);

        if ($base64UrlSignature !== $signatureProvided) {
            return false;
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : false;
    }

}
?>