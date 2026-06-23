<?php

namespace App\Enums;

use Library\Enum;

class ResponseStatusEnum extends Enum {  
    
    const SUCCESS               = 200;
    
    const BAD_REQUEST           = 400;
    const UNAUTHORIZED          = 401;    
    const INVALID_SINGNATURE    = 402;
    const FORBIDDEN             = 403;
    const NOT_FOUND             = 404; 
    const TOO_MANY_REQUEST      = 429;
    const LIMIT_EXCEEDED        = 430;
    const CLIENT_CANCELED       = 460;
    
    const UPGRADE               = 300;
    const TRY_AFTER_SOME_TIME   = 301;
    const PRODUCT_NOT_EXIST     = 302;
    const UNABLE_TO_PROCESS     = 303;
    const INPUT_MISSING         = 304;  
    const INVALID_INPUT         = 305;
    const NO_DATA_FOUND         = 306;
    const CONTACT_ADMIN         = 307;    
    const ALREADY_REGISTERED    = 308;
    const URL_EXPIRED           = 309;
    const INVALID_OTP           = 310;
    const ACCOUNT_DEACTIVATED   = 311;
    const INPUT_CHAR_LIMIT      = 312;
    
    
    const INVALID_PASSWORD      = 313;
    const NO_USER               = 314;
    const ALREADY_VIEWED        = 315;
    const INSUFFICIENT_CREDIT   = 316;
    const ALREADY_PAID          = 317;
    const INVALID_PLAN          = 318;
    const INVALID_SUBSCRIPTION  = 319;
    const NO_CONTACTS           = 320;
    const INVALID_SESSION_ID    = 321;
    const INVALID_EMAIL         = 322;
    const BANNED                = 323;
    const TRIAL_ALREADY_TAKEN   = 324; 
    const INVALID_FILE_TYPE     = 325;
    const NO_SUBSCRIPTION       = 326;
    const LIST_EXISTS           = 327;
}