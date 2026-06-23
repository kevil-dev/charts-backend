<?php

namespace Library;

class Red {

	static private $r = NULL;
    static private $host_array_obj = array();
    
    static private $REDIS_HOST=_RED_HOST_;
	static private $REDIS_PORT=_RED_PORT_;
	
	static function init($server=null) {

		if($server){
			$REDIS_HOST = $server;
			global $REDIS_PORT;
		}else{
			$REDIS_PORT = self::$REDIS_PORT;
			$REDIS_HOST = self::$REDIS_HOST;
		}
		
		if (!(isset(self::$host_array_obj[$REDIS_HOST]) && self::$host_array_obj[$REDIS_HOST])) {
			$r = new \Redis();
			try{
				$r->connect($REDIS_HOST, $REDIS_PORT); 
				self::$host_array_obj[$REDIS_HOST] = $r;
			}catch(Exception $e){
				self::$host_array_obj[$REDIS_HOST] = false;
			}
        }
        
		self::$r = self::$host_array_obj[$REDIS_HOST];
		return self::$r;
	}
	
	static function close($server=null){
		if($server){
			$REDIS_HOST = $server;
			global $REDIS_PORT;
		}else{
			$REDIS_PORT = self::$REDIS_PORT;
			$REDIS_HOST = self::$REDIS_HOST;
		}
		self::$r->close();
		unset(self::$host_array_obj[$REDIS_HOST]);
	}
	
	static function checkRedisSocket(){
		return true;// temp
		if(isset(self::$r->socket)){
			return true;
		}else{
			return false;
		}
	}
	
	static function set($key,$value,$expire=null,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->set($key, $value, $expire);
	}

	static function get($key,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->get($key);
	}
	
	static function ttl($key,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->ttl($key);
	}
	
	static function setTimeout($key,$expire,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->setTimeout($key, $expire);
	}

	static function exists($key,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->exists($key);
	}
	
	static function incr($key,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->incr($key);
	}
	
	static function ping($server=null) {
		self::init($server);
		return self::$r->ping();
	}

	static function delete($key,$server=null) {
		if(self::checkRedisSocket()===false){return false;}
		self::init($server);
		return self::$r->delete($key,$server);
	}
	static function rPopMulti($key,$server=null) {//not used yet, test fn
		self::init($server);
		return self::$r->multi()->rPop($key)->rPop($key)->rPop($key)->rPop($key)->rPop($key)->exec();
	}
	static function rPop($key,$server=null) {
		self::init($server);
		return self::$r->rPop($key);
	}
	static function lPop($key,$server=null) {
		self::init($server);
		return self::$r->lPop($key);
	}
	static function lRem($key,$value,$count,$server=null) {
		self::init($server);
		return self::$r->lRem($key,$value,$count);
	}
	static function lLen($key,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->lLen($key);
	}
	static function lIndex($key,$index,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->lIndex($key,$index);
	}
	static function lTrim($key,$start,$end,$server=null){
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->lTrim($key,$start, $end);
	}
	static function lPush($key,$value,$values,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		if($values){
			foreach($values as $v){
				$x = self::$r->lPush($key,$v);
			}
			return $x;
		}else{
			return self::$r->lPush($key,$value);
		}
	}
	
	/*
	 * @return LONG The new length of the list in case of success, FALSE in case of Failure.
	 */
	static function rPush($key,$value,$values='',$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		if($values){
			foreach($values as $v){
				$x = self::$r->rPush($key,$v);
			}
			return $x;
		}else{
			return self::$r->rPush($key,$value);
		}
	}
	static function rPushWithLrem($key,$value,$values='',$server=null) {
		self::init($server);
		self::$r->pipeline();
		if($values){
			foreach($values as $v){
				self::$r->lRem($key,$v,1);
				$x = self::$r->rPush($key,$v);
			}
		}else{
			self::$r->lRem($key,$value,1);
			$x = self::$r->rPush($key,$value);
		}
		self::$r->exec();
		self::close($server);
		return $x;
	}
	
	static function lRange($key,$offset,$limit,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->lRange($key,$offset,$limit);
	}
	
	static function info($server=null) {
		self::init($server);
		return self::$r->info();
	}

	static function expire($key, $seconds, $server=null) {
		self::init($server);
		$o = self::$r->expire($key,$seconds);
		self::close($server);
		return $o;
	}

	/*******Hash********/
	static function HGETALL($key,$server=null) {
		self::init($server);
		return self::$r->HGETALL($key);
	}

	static function HSET($key,$field,$value,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		echo 'here';
		return self::$r->HSET($key,$field,$value);
	}
	static function HGET($key,$field,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->HGET($key,$field);
	}
	
	static function HDEL($key,$field,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->HDEL($key,$field);
	}	
	
	static function HLEN($key,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->HLEN($key);
	}	
	
	static function HINCRBY($key,$field,$by,$server=null){
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		return self::$r->hIncrBy($key,$field,$by);
	}
			
	static function HSETPipeline($obj,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		self::$r->pipeline();
		foreach($obj as $x){
			self::$r->HSET($x['key'],$x['field'],$x['value']);
		}
		self::$r->exec();
		//$x = self::$r->zAdd($key,$score,$value);
		self::close($server);
	}

	static function HGETPipeline(&$obj,$server=null) {
		self::init($server);
		if(self::checkRedisSocket()===false){return false;}
		self::$r->pipeline();
		foreach($obj as $x){
			self::$r->HGET($x['key'],$x['field']);
		}
		$result = self::$r->exec();
		//$x = self::$r->zAdd($key,$score,$value);
		self::close($server);
		return $result;
	}

	static function HDELPipeline($obj,$server=null) {
		self::init($server);
		self::$r->pipeline();
		foreach($obj as $x){
			self::$r->HDEL($x['key'],$x['field']);
		}
		self::$r->exec();
		//$x = self::$r->zAdd($key,$score,$value);
		self::close($server);
	}
	
	/********SORTED SETS********/
	static function zAddPipeline($obj,$server=null) {
		self::init($server);
		try{
                    self::$r->pipeline();
                }catch(RedisException $e){
                    self::init($server);
                    self::$r->pipeline();
                }
		foreach($obj as $x){
			self::$r->zAdd($x['key'],$x['score'],$x['value']);
		}
		self::$r->exec();
		//$x = self::$r->zAdd($key,$score,$value);
		self::close($server);
		return $x;
	}
	
	static function zDeletePipeline($obj,$server=null) {
		self::init($server);
		self::$r->pipeline();
		foreach($obj as $x){
			self::$r->zDelete($x['key'],$x['value']);
		}
		self::$r->exec();
		self::close($server);
	}	
	
	static function zAdd($key,$score,$value,$server=null) {
		self::init($server);
		return self::$r->zAdd($key,$score,$value);
	}
	
	static function zRank($key,$value,$server=null) {
		self::init($server);
		return self::$r->zRank($key,$value);
	}

	static function zScore($key,$value,$server=null) {
		self::init($server);
		return self::$r->zScore($key,$value);
	}

	static function zScorePipeline($obj,$server=null) {
		self::init($server);
		self::$r->pipeline();
		foreach($obj as $x){
			self::$r->zScore($x['key'],$x['value']);
		}
		$result = self::$r->exec();
		self::close($server);
		return $result;
	}
				
	static function zRankPipeline($obj,$server=null) {
		self::init($server);
		self::$r->pipeline();
		foreach($obj as $x){
			self::$r->zRank($x['key'],$x['value']);
		}
		$result = self::$r->exec();
		//$x = self::$r->zAdd($key,$score,$value);
		self::close($server);
		return $result;
	}
			
	static function zSize($key,$server=null) {
		self::init($server);
		return self::$r->zSize($key);
	}
	
	static function zCountPipeline($obj,$server=null){
		self::init($server);
		self::$r->pipeline();
		foreach($obj as $x){
			self::$r->zCount($x['key'],$x['min'],$x['max']);
		}
		$result = self::$r->exec();
		self::close($server);
		return $result;
	}	
	
	static function zCount($key,$min='-inf',$max='+inf',$server=null) {
		self::init($server);
		return self::$r->zCount($key,$min,$max);
	}
	static function zRange($key,$offset,$limit,$server=null) {
		self::init($server);
		return self::$r->zRange($key,$offset,$limit);
	}
	static function zRangeByScore($key,$min,$max,$arr,$server=null) {
		self::init($server);
		if($arr){
			return self::$r->zRangeByScore($key,$min,$max,$arr);
		}else{
			return self::$r->zRangeByScore($key,$min,$max);
		}
	}
	static function zRangeByScorePipeline($obj,$server=null) {
		self::init($server);
		self::$r->pipeline();
		foreach($obj as $x){
			if($x['arr']){
				self::$r->zRangeByScore($x['key'],$x['min'],$x['max'],$x['arr']);
			}else{
				self::$r->zRangeByScore($x['key'],$x['min'],$x['max']);
			}
			//self::$r->zRank($x['key'],$x['value']);
		}
		$result = self::$r->exec();
		self::close($server);
		return $result;
	}

	static function zRevRangeByScore($key,$offset,$limit,$arr,$server=null) {
		self::init($server);
		if($arr){
			$result =  self::$r->zRevRangeByScore($key,$offset,$limit,$arr);
		}else{
			$result =  self::$r->zRevRangeByScore($key,$offset,$limit);
		}
		self::close($server);
		return $result;
	}
	static function zRevRangeByScorePipeline($obj,$server=null) {
		self::init($server);
		self::$r->pipeline();
		foreach($obj as $x){
			if($x['arr']){
				self::$r->zRevRangeByScore($x['key'],$x['min'],$x['max'],$x['arr']);
			}else{
				self::$r->zRevRangeByScore($x['key'],$x['min'],$x['max']);
			}
		}
		$result = self::$r->exec();
		self::close($server);
		return $result;
	}
	static function zRevRange($key,$offset,$limit,$server=null) {
		self::init($server);
		return self::$r->zRevRange($key,$offset,$limit);
	}
	static function zDelete($key,$value,$server=null) {
		self::init($server);
		return self::$r->zDelete($key,$value);
	}
	static function zIncrementBy($key,$value,$by,$server=null) {
		self::init($server);
		return self::$r->zIncrBy($key, $by, $value);
	}	
	static function zUnion($newkey,$keyarray,$weightarray,$agg_fn,$server=null) {
		self::init($server);
		if($weightarray and $agg_fn){
			return self::$r->zUnion($newkey,$keyarray,$weightarray,$agg_fn);
		}else{
			return self::$r->zUnion($newkey,$keyarray);
		}
	}
	static function zRemRangeByScore($key,$min,$max,$server=null) {
		self::init($server);
		return self::$r->zRemRangeByScore($key,$min,$max);
	}
	static function zRemRangeByRank($key,$start,$end,$server=null) {
		self::init($server);
		return self::$r->zRemRangeByRank($key,$start,$end);
	}
	
	/*********************SETS*********************************/
        static function SADD($key,$value,$server=null) {
            self::init($server);
            return self::$r->SADD($key,$value);
	}
        
        static function SREM($key,$value,$server=null) {
            self::init($server);
            return self::$r->SREM($key,$value);
	}
        
        static function SMEMBERS($key,$server=null) {
            self::init($server);
            return self::$r->SMEMBERS($key);
	}
        
        static function SRANDMEMBER($key,$server=null) {
            self::init($server);
            return self::$r->SRANDMEMBER($key);
	}
	
	/*********************HyperLogLog*********************************/
        static function PFADD($key,$value,$server=null) {
            self::init($server);
            return self::$r->PFADD($key,$value);
	}
        
        static function PFCOUNT($key,$server=null) {
            self::init($server);
            return self::$r->PFCOUNT($key);
	}

}
    
?>
