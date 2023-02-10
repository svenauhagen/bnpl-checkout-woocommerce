<?php

namespace Mondu\Exceptions;

class MonduException extends \Exception {

	public function get_api_message() {

		$msg = array();
		$body = $this->getBody();

		foreach ($body['errors'] as $message) {
			if (is_array($message)) {
				$msg[] = $message['name'] . ' ' . $message['details'];
			}
		}

		return $msg;
	}
}
