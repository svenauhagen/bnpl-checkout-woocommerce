<?php

namespace Mondu\Mondu;

use Mondu\Mondu\MonduGateway;
use Mondu\Plugin;

class GatewayInstallment extends MonduGateway {
	public function __construct() {
		$this->id                 = Plugin::PAYMENT_METHODS['installment'];
		$this->title              = __('Mondu Installments', 'mondu');
		$this->description        = __('Split payments - Pay Later in Installments by Direct Debit', 'mondu');
		$this->method_description = __('Split payments - Pay Later in Installments by Direct Debit', 'mondu');
		$this->has_fields         = true;

		parent::__construct();
	}
}
