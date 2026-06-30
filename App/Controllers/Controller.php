<?php

namespace App\Controllers;

use Library\Input;
use GuzzleHttp\Client;
use Rakit\Validation\Validator;

use App\Traits\ToolsTrait;
use App\Traits\ThrottleTrait;
use App\Traits\EntitlementTrait;

// use App\Models\AccountModel;
// use App\Models\PaymentModel;

use App\Enums\ResponseStatusEnum;
// use App\Enums\StripeStatesEnum;
// use App\Enums\PreferenceEnum;
// use App\Enums\TypeEnum;

class Controller
{

	var $vars;
	public $input;
	public $client;
	public $validator;

	// public $userobj;
	// public $payment;
	public $auto_id;
	public $email_id;
	// public $paid;
	// public $trial;
	// public $payment_status;
	// public $current_plan_id;

	public $mw;

	public $router_controller;
	public $router_method;

	public $auth_user_key = "\App\Controllers\Controller:verifyAuthToken";
	public $user_token;

	// public $account_mdl;
	// public $payment_mdl;

	public $payload;
	public $content_type;
	public $headers;

	public $pagination_limit;
	public $pagination_offset;

	public static $skip_auth = false;

	use ToolsTrait, ThrottleTrait, EntitlementTrait;

	public function __construct($vars = [])
	{
		$this->vars = $vars;
		$this->input = new Input();
		$this->client = new Client();
		$this->validator = new Validator();
		// $this->account_mdl = new AccountModel();
		// $this->payment_mdl = new PaymentModel();

		$this->router_controller = isset($vars["__controller"]) ? $vars["__controller"] : "";
		$this->router_method = isset($vars["__method"]) ? $vars["__method"] : "";
		$this->payload = $this->getPayloads($this->input->method());
		$this->user_token = isset($this->input->request_headers()["Authorization"]) ? $this->input->request_headers()["Authorization"] : MP_TOKEN;
		$this->content_type = isset($this->input->request_headers()["Content-Type"]) ? $this->input->request_headers()["Content-Type"] : "";
		$this->headers = $this->getHeaders();
	}

	public function middleware($middlewares)
	{
		foreach ($middlewares as $middleware_key => $middleware_value):
			$this->runMiddleware($middleware_key, $middleware_value);
		endforeach;
	}

	public function runMiddleware($middleware_name, $vars = [])
	{
		$middleware_params = $vars["params"] ?? [];
		$arr = explode(":", $middleware_name);
		$controller = $arr[0];
		$method = $arr[1];

		$is_valid_token = $this->router_method == "login" ? 1 : empty($this->user_token);
		$except = isset($vars["except"]) && count($vars["except"]) > 0 && $is_valid_token ? $vars["except"] : [];

		if ($except):
			$class_name = substr(strrchr($vars["class"], "\\"), 1);
			$class_name_router = substr(strrchr($this->router_controller, "\\"), 1);
			if ($class_name == $class_name_router):
				if (in_array($this->router_method, $except)):
					return false;
				endif;
			endif;
		endif;

		$this->mw[$middleware_name] = new $controller();
		!empty(self::$skip_auth) ? "" : call_user_func_array([$this->mw[$middleware_name], $method], $middleware_params);
	}

	public function verifyAuthToken()
	{
		$headerToken = str_replace('Bearer ', '', $this->user_token ?? '');
		$cookieToken = $this->input->cookie('mp_token') ?? '';
		$token = !empty($headerToken) && $headerToken !== MP_TOKEN ? $headerToken : $cookieToken;

		if (empty($token)) {
			$this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
		}
		$payload = \Library\Jwt::validate($token);
		if (!$payload) {
			$this->sendJson(ResponseStatusEnum::UNAUTHORIZED);
		}
		$this->auto_id  = (int) decrypt($payload['user_id']);
		$this->email_id = $payload['email'] ?? '';
	}
	public function resolveUserIfPresent(): void
	{
		$headerToken = str_replace('Bearer ', '', $this->user_token ?? '');
		$cookieToken = $this->input->cookie('mp_token') ?? '';
		$token = !empty($headerToken) && $headerToken !== MP_TOKEN ? $headerToken : $cookieToken;

		if (empty($token)) {
			return;
		}
		$payload = \Library\Jwt::validate($token);
		if (!$payload) {
			return;
		}
		$this->auto_id  = (int) decrypt($payload['user_id']);
		$this->email_id = $payload['email'] ?? '';
	}

	// public function setObj($user) { ... }
	// public function isPaid() { ... }
	// public function is_trial() { ... }
	// public function trialTaken() { ... }
	// public function upgrade() { ... }
	// public function info() { ... }

	public function setPagination()
	{
		$current_page = isset($this->payload["page"]) ? (int) $this->payload["page"] : 1;
		$per_page = isset($this->payload["limit"]) && (int) $this->payload["limit"] > 0
			? (int) $this->payload["limit"]
			: 50;

		$this->pagination_limit  = $per_page;
		$this->pagination_offset = ($current_page - 1) * $per_page;
	}
	public function getPagination($total = 0, $results = [])
	{
		$current_page = isset($this->payload["page"]) ? $this->payload["page"] : 1;
		$per_page = isset($this->payload["limit"]) ? $this->payload["limit"] : 0;
		$last_page = (int)$per_page > 0 ? ceil($total / $per_page) : 1;

		$data = [
			"results"       => $results,
			"total"         => (int)$total,
			"per_page"      => (int)$per_page,
			"current_page"  => (int)$current_page,
			"last_page"     => (int)$last_page < 1 ? 1 : $last_page,
		];
		return $data;
	}
	public function paginate($total = 0, $results = [])
	{
		$this->sendJson(ResponseStatusEnum::SUCCESS, "", $this->getPagination($total, $results));
	}
	// public function get_type() { ... }
	// public function get_type_name($type) { ... }
	public function validateInput($rules, $payload = [])
	{
		$payload = count($payload) > 0 ? $payload : $this->payload;
		$validation = $this->validator->validate($payload, $rules);
		if ($validation->fails()):
			$err = $validation->errors()->firstOfAll();
			$error_msg = implode(", ", $err);
			$this->sendJson(ResponseStatusEnum::BAD_REQUEST, $error_msg);
		endif;
		return true;
	}
	protected function getHeaders(): array
	{
		$headers = [
			'Authorization' => $this->user_token,
		];

		if (!empty($this->content_type)) {
			$headers['Content-type'] = $this->content_type;
		}

		return $headers;
	}
}
