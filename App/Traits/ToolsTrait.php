<?php
namespace App\Traits;

use Library\Input;
use App\Enums\ResponseStatusEnum;
use App\Enums\RestrictTypeEnum;

trait ToolsTrait {   
    
    public function getPayloads($request_method) {
        $this->input = new Input();
        $input = $request_method == "post" ? $this->input->post() : $this->input->get();
        $input = self::trim_arr($input); 
        
        return $input;
    }    
    public function trim_arr(&$input_array) {
        if(is_array($input_array)):
            foreach($input_array as $key => &$val):
                if(is_array($val)):
                    self::trim_arr($val);
                else:
                    $input_array[$key] = trim($val);
                endif;
            endforeach;
        else:
            $input_array = trim($input_array);
        endif;        
        return $input_array;
    }
    public function input_char_limit($payload, $len = 100) {
        return true;
        if($payload):
            foreach($payload as $key => $value):
                if(strlen($value) > $len):
                    $this->sendJson(ResponseStatusEnum::INPUT_CHAR_LIMIT, $key);
                endif;
            endforeach;
        endif;        
        return true;
    }    
    public function restrict($mdl, $type, $id = null) {
        $plans = plans($this->auto_id, 1, 1);
        
        $this->current_plan_id = empty($this->current_plan_id) ? FREE_MONTH : $this->current_plan_id;        
        $plan = isset($plans[$this->current_plan_id]) ? $plans[$this->current_plan_id] : [];
        
        if((!$this->isPaid() && !RESTRICT_FREE_USER) || ($this->is_trial() && !RESTRICT_TRIAL_USER) || ($this->isPaid() && !RESTRICT_PAID_USER)):
            return true;
        endif;
        
        if(empty($plan)):
            $this->sendJson(ResponseStatusEnum::LIMIT_EXCEEDED);
        endif;
        
        $val = isset($plan[$type]) ? $plan[$type] : 0;        
        if(empty($val)):
            $this->sendJson(ResponseStatusEnum::LIMIT_EXCEEDED);
        endif;
        
        $count = 0;
        $vars = ["auto_id" => $this->auto_id];
        switch($type):
            case RestrictTypeEnum::SEARCH:  
                $vars["start"] = date("Y-m-d 00:00:00");
                $vars["end"] = date("Y-m-d 23:59:59");                
                $count = $mdl->get_log($vars, 1)[0]["ct"]; 
            break;
            case RestrictTypeEnum::LIST:  
                $count = $mdl->get($vars, 1)[0]["ct"];                
            break;
            case RestrictTypeEnum::LIST_CONTACT:
                $vars["id"] = $id;
                $count = $mdl->get($vars)[0]["total"];
            break;
        endswitch;
        
        if($count < $val):
            return true;
        endif;        
        
        $this->sendJson(ResponseStatusEnum::LIMIT_EXCEEDED);        
    }
    
    public function sendJson($status, $msg = "", $data = []) {        
        $code_arr = [
            ResponseStatusEnum::SUCCESS                 => "Success!",
            ResponseStatusEnum::INVALID_SINGNATURE      => "Invalid signature!",
            ResponseStatusEnum::FORBIDDEN               => "Forbidden",
            ResponseStatusEnum::UNAUTHORIZED            => "Unauthorized!",    
            ResponseStatusEnum::INPUT_MISSING           => "Input parameter missing - ", 
            ResponseStatusEnum::INVALID_INPUT           => "Invalid input",
            ResponseStatusEnum::BAD_REQUEST             => "Bad request!",
            ResponseStatusEnum::NO_DATA_FOUND           => "No data!",
            ResponseStatusEnum::UNABLE_TO_PROCESS       => "Unable to process your request!",
            ResponseStatusEnum::CONTACT_ADMIN           => "Please contact admin!",
            ResponseStatusEnum::UPGRADE                 => "You must have a paid plan to access this feature!",
            ResponseStatusEnum::NOT_FOUND               => "We can't seem to find the page you're looking for.",
            ResponseStatusEnum::ALREADY_REGISTERED      => "The email address is already registered with us!",
            ResponseStatusEnum::INVALID_PASSWORD        => "The password you entered is invalid!",
            ResponseStatusEnum::NO_USER                 => "We couldn't find an account with this email address!", 
            ResponseStatusEnum::ALREADY_VIEWED          => "The contact was already viewed!",
            ResponseStatusEnum::INSUFFICIENT_CREDIT     => "You don't have enough credit to view this contact!",
            ResponseStatusEnum::ALREADY_PAID            => "You are a paid user!",
            ResponseStatusEnum::INVALID_PLAN            => "Invalid plan!",
            ResponseStatusEnum::INVALID_SUBSCRIPTION    => "Invalid subscription!",
            ResponseStatusEnum::INVALID_SESSION_ID      => "You can't use the session ID more than once!",
            ResponseStatusEnum::INVALID_EMAIL           => "The email address you entered is invalid!",
            ResponseStatusEnum::BANNED                  => "Your account has been banned!",
            ResponseStatusEnum::TRIAL_ALREADY_TAKEN     => "You cannot take the trial again since it has already been completed!",
            ResponseStatusEnum::INVALID_FILE_TYPE       => "Invalid file type!",   
            ResponseStatusEnum::NO_SUBSCRIPTION         => "No active subscriptions were found!",
            ResponseStatusEnum::NO_CONTACTS             => "No contacts!",
            ResponseStatusEnum::LIST_EXISTS             => "List name already exists!",
            ResponseStatusEnum::LIMIT_EXCEEDED          => "Limit exceeded!",
            ResponseStatusEnum::TOO_MANY_REQUEST        => "Too many requests!",
            ResponseStatusEnum::URL_EXPIRED             => "Url expired",
            ResponseStatusEnum::INVALID_OTP             => "Invalid otp",
            ResponseStatusEnum::CLIENT_CANCELED         => "Client closed request",
            ResponseStatusEnum::ACCOUNT_DEACTIVATED     => "Account deactivated!",
            ResponseStatusEnum::INPUT_CHAR_LIMIT        => "The value of field is too long",
        ];
        
        $http_msg = str_replace("_", " ", ResponseStatusEnum::getConstantName($status));
        $msg = !empty($msg) ? $code_arr[$status]." - ".$msg : $code_arr[$status];
        $result = ["status" => $status, "msg" => $msg];
        
        if($status == ResponseStatusEnum::SUCCESS):
            $result["data"] = $data;
        endif;       
        
        header("HTTP/1.1 $status $http_msg");
        header('Content-Type: application/json; charset=utf-8');
        echo jsonEncode($result); die;        
    }
    
    public function sendRawJson($data) {
        $status = $data["status"];
        header("HTTP/1.1 $status");
        header('Content-Type: application/json; charset=utf-8');
        echo jsonEncode($data); die;
    }
}
?>