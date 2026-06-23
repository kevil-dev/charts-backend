<?php

namespace Library;

class Jwt {

    static private $secret = JWT_SECRET;
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

        // split the token
        $tokenParts = explode('.', $jwt);
        $header = base64_decode($tokenParts[0]);
        $payload = base64_decode($tokenParts[1]);
        $signatureProvided = $tokenParts[2];

        // check the expiration time - note this will cause an error if there is no 'exp' claim in the token
        // echo $expiration = Carbon::createFromTimestamp(json_decode($payload)->exp);
        // $tokenExpired = (Carbon::now()->diffInSeconds($expiration, false) < 0);

        // build a signature based on the header and payload using the secret
        $base64UrlHeader = base64UrlEncode($header);
        $base64UrlPayload = base64UrlEncode($payload);
        $signature = hash_hmac(self::$hash_algorith, $base64UrlHeader . "." . $base64UrlPayload, self::$secret, true);
        $base64UrlSignature = base64UrlEncode($signature);

        // verify it matches the signature provided in the token
        $signatureValid = ($base64UrlSignature === $signatureProvided);

        // echo "Header:\n" . $header . "\n";
        // echo "Payload:\n" . $payload . "\n";

        // if ($tokenExpired) {
            // echo "Token has expired.\n";
        // } else {
            // echo "Token has not expired yet.\n";
        // }

        if ($signatureValid) {
            // echo "The signature is valid.\n";
            return true;
        } else {
            // echo "The signature is NOT valid\n";
            return false;
        }
    }

}
?>