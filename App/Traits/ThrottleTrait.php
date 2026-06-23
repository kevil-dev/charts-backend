<?
namespace App\Traits;
use RateLimit\Exception\LimitExceeded;
use RateLimit\Rate;
use RateLimit\PredisRateLimiter;

use App\Models\PredisModel;
use App\Enums\RateLimitTypeEnum;
use App\Enums\ResponseStatusEnum;

trait ThrottleTrait {
    
    public function rateLimitMe($minute) {
        
        if(DISABLE_RATE_LIMIT):
            return;
        endif;
        
        global $router;
        $predis_mdl = new PredisModel();
        
        $predis_throttle_client = $predis_mdl->redis_throttle_client();
        $predis_client = $predis_mdl->redis_throttle_client();
        $throttle_queue_key = $predis_mdl->get_key("throttle_queue");
        
        //Cannot connect with Predis
        if(empty($predis_throttle_client)):
            return;
        endif;
        
        $ipLong = ip2long(get_client_ip());
        $ipLong = empty($ipLong) ? 0 : $ipLong;
        
        $method = $router->getRequestMethod();
        $enpoint = $router->getCurrentUri();
        $apiKey = $ipLong."@".$method.":".$enpoint;
        
        $rates = [
            60      => $minute,        // 1 min
            300     => $minute * 5,    // 5 mins
            600     => $minute * 10,   // 10 min
            3600    => $minute * 60,   // 1 hr            
            21600   => $minute * 360,  // 6 hr   
            43200   => $minute * 720,  // 12 hr
            86400   => $minute * 1440, // 1 day
            604800  => $minute * 10080 // 1 week
        ];
        
        foreach($rates as $interval_seconds => $operations):
            $rate = Rate::custom($operations, $interval_seconds);            
            $rateLimiter = new PredisRateLimiter($rate, $predis_throttle_client);
            try {
                $rateLimiter->limit($apiKey);
            } catch (LimitExceeded $exception) {
                $queue = [
                    "t"     => RateLimitTypeEnum::LIMIT_EXCEEDED,
                    "i"     => $ipLong,
                    "m"     => $method,
                    "e"     => $enpoint,
                    "rk"    => $apiKey,
                    "ro"    => $rate->getOperations(),
                    "ri"    => $rate->getInterval(),
                    "ts"    => strtotime('now'),
                    "rl"    => $rates,
                ];
                try {
                    $predis_client->rpush($throttle_queue_key, json_encode($queue));
                } catch (\Exception $e) {
                    
                }
                $this->sendJson(ResponseStatusEnum::TOO_MANY_REQUEST);                            
            }            
        endforeach;
    }
}
?>