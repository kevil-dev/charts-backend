<?php
if(!function_exists("base64UrlEncode")):
    function base64UrlEncode($text) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($text));
    }
endif;

if (!function_exists("remove_invisible_characters")):
    function remove_invisible_characters($str, $url_encoded = true) {
        $non_displayables = [];
        if ($url_encoded):
            $non_displayables[] = '/%0[0-8bcef]/i';
            $non_displayables[] = '/%1[0-9a-f]/i';
            $non_displayables[] = '/%7f/i';
        endif;        
        $non_displayables[] = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/S';
        
        do {
            $str = preg_replace($non_displayables, '', $str, -1, $count);
        }
        while ($count);
        return $str;
    }
endif;

if(!function_exists("generate_random_string")):
    function generate_random_string($length = 10) {
        return substr(str_shuffle(str_repeat($x = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ", ceil($length/strlen($x)) )),1,$length);
    }
endif;

if(!function_exists("jsonEncode")):
    function jsonEncode($data) {
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return $json;
    }
endif;

if(!function_exists("encryptString")):
    function encryptString($string) {
        $cipher = "AES-128-CTR"; 
        $iv = substr(openssl_random_pseudo_bytes(16), 0, 4); 
        $ciphertext = openssl_encrypt($string, $cipher, ENCRYPTION_KEY, 0, $iv); 
        return rtrim(strtr(base64_encode($iv . $ciphertext), '+/', '-_'), '=');
    }
endif;

if(!function_exists("decryptString")):
    function decryptString($ciphertext_base64) {
        $cipher = "AES-128-CTR"; 
        $ciphertext = base64_decode(strtr($ciphertext_base64, '-_', '+/')); 
        $iv = substr($ciphertext, 0, 4); 
        $ciphertext_raw = substr($ciphertext, 4); 
        return openssl_decrypt($ciphertext_raw, $cipher, ENCRYPTION_KEY, 0, $iv); 
    }
endif;

if(!function_exists("handleSpecialChar")):
    function handleSpecialChar($text, $remove_tags = 0) {
        $text = htmlspecialchars_decode($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = stripslashes($text);
        $text = $remove_tags ? strip_tags($text) : $text;
        return $text;
    }
endif;

if(!function_exists("is_valid_email")):
    function is_valid_email($email) {    
        if(!$email || strlen($email = trim($email)) == 0 or preg_match("/^[\s]+$/",$email)):
            return false;
        endif;
        
        if(preg_match("/^[_+a-zA-Z0-9-]+(\.[_+a-zA-Z0-9-]+)*@[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]{1,})*\.([a-zA-Z]{2,}){1}$/",$email)):
            return true;
        endif;
        
        return false;
    }
endif;

if(!function_exists("is_valid_url")):
    function is_valid_url($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
endif;

if(!function_exists("trim_string")):
    function trim_string($txt, $limit = 300) {
        preg_match_all("/[A-Z]/", $txt, $r);
        if(count($r[0])<5):$limit += 3;endif;
        
        if(empty($txt)):
            return $txt;
        endif;
        
        $len = strlen($txt);
        if($len > $limit):
            $tmptxt = substr($txt, 0, $limit - 3);
            if($len > 80):
                $x = strrpos($tmptxt, " ");
                if($x):$tmptxt = substr($tmptxt, 0, $x);endif;
            endif;
            $txt = $tmptxt.'..';
        endif;
        
        return $txt;
    }
endif;

if(!function_exists("trimValues")):
    function trimValues($data) {
        $data = is_array($data) ? array_map('trim', $data) : trim($data);
        $result = is_array($data) ? array_map('strtolower', $data) : strtolower($data);
        return $result;
    }
endif;

if(!function_exists("jsonDecode")):
    function jsonDecode($data, $arr = true) {
        return json_decode($data, $arr);
    }
endif;

if(!function_exists('get_client_ip')):
    function get_client_ip() {
        $ip_addr = "";
        if (isset($_SERVER['HTTP_CLIENT_IP'])):
            $ip_addr = $_SERVER['HTTP_CLIENT_IP'];
        elseif(isset($_SERVER['HTTP_X_FORWARDED_FOR'])):
            $ip_addr = $_SERVER['HTTP_X_FORWARDED_FOR'];
            $ip_add_arr = explode(',', $ip_addr);
            foreach($ip_add_arr as $ip):
                if(!empty($ip)): $ip_addr = $ip; break; endif;
            endforeach;
        elseif(isset($_SERVER['HTTP_X_FORWARDED'])):
            $ip_addr = $_SERVER['HTTP_X_FORWARDED'];
        elseif(isset($_SERVER['HTTP_FORWARDED_FOR'])):
            $ip_addr = $_SERVER['HTTP_FORWARDED_FOR'];
        elseif(isset($_SERVER['HTTP_FORWARDED'])):
            $ip_addr = $_SERVER['HTTP_FORWARDED'];
        elseif(isset($_SERVER['REMOTE_ADDR'])):
            $ip_addr = $_SERVER['REMOTE_ADDR'];
        endif;
        
        return $ip_addr;
    }
endif;

if(!function_exists("include_with_variables")):
    function include_with_variables($filePath, $variables = []) {
        $output = "";
        if(file_exists($filePath)):
            extract($variables);
            
            ob_start();
            include $filePath;
            $output = ob_get_clean();
        endif;
        
        return $output;
    }
endif;

if(!function_exists("generate_otp")):
    function generate_otp($digits = 4) {
        $generator = "1357902468";
        $otp = "";
        for($i = 1; $i <= $digits; $i++):
            $otp .= substr($generator, (rand()%(strlen($generator))), 1);
        endfor;
        return $otp;
    }
endif;

if(!function_exists("is_valid_date")):
    function is_valid_date($date) {
        list($year, $month, $day) = explode('-', $date);
        $year = (int)$year;
        $month = (int)$month;
        $day = (int)$day;    
        return checkdate($month, $day, $year);
    }
endif;

if(!function_exists("sanitize_query")):
    function sanitize_query($query) {
        $query = filter_var($query, FILTER_SANITIZE_STRING);
        $query = strip_ctrl_char($query);
        $query = escape_solr_reserved_chars($query);
        $query = htmlentities($query, ENT_NOQUOTES, 'UTF-8');         
        return $query;
    }
endif;

// Bulk encrypts the int "id" field (or a custom key) for each array item, uses encrypt()
if(!function_exists("encryptIds")):
    function encryptIds(array $items, string $keyName = 'id'): array {
        return array_map(function ($item) use ($keyName) {
            if (isset($item[$keyName])) {
                $item[$keyName] = encrypt((string)$item[$keyName]);
            }
            return $item;
        }, $items);
    }
endif;

if (!function_exists("getLogger")):
    function getLogger(string $name = "app", ?string $subDir = null, ?string $logDir = null): array {
        $logDir = $logDir ?? (DOCUMENT_ROOT . 'Logs');
    
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
    
        if ($subDir !== null) {
            $logDir = rtrim($logDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($subDir, DIRECTORY_SEPARATOR);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0775, true);
            }
        }
    
        $logFile = $logDir . DIRECTORY_SEPARATOR . $name . '_' . date('Ymd_His') . '.log';
        $fh = fopen($logFile, 'a');
    
        if (!$fh) {
            throw new \RuntimeException("Failed to open log file: $logFile");
        }
    
        $logger = function (string $msg) use ($fh, $name) {
            $timestamp = date('[Y-m-d H:i:s]');
            fwrite($fh, "$timestamp [$name] $msg\n");
            fflush($fh);
        };
    
        $closer = function () use ($fh) {
            fclose($fh);
        };
    
        return [$logger, $closer];
    }    
endif;

if(!function_exists('encrypt')):
    function encrypt($string) {
        $ENC_DEC_KEY = "─.鳶山夕景";	
        $result = '';
        for($i=0; $i<strlen($string); $i++) {
            $char = substr($string, $i, 1);
            $keychar = substr($ENC_DEC_KEY, ($i % strlen($ENC_DEC_KEY))-1, 1);
            $char = chr(ord($char)+ord($keychar));
            $result.=$char;
        }
        $result = base64_encode($result);
        return $result;
    }
endif;

if(!function_exists('decrypt')):
    function decrypt($string) {
        $ENC_DEC_KEY = "─.鳶山夕景";	
        $result = '';
        $string = base64_decode(str_replace(" ","+",$string));
        
        for($i=0; $i<strlen($string); $i++) {
            $char = substr($string, $i, 1);
            $keychar = substr($ENC_DEC_KEY, ($i % strlen($ENC_DEC_KEY))-1, 1);
            $char = chr(ord($char)-ord($keychar));
            $result.=$char;
        }
        return $result;
    }
endif;