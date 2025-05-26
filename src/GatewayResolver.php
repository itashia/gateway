<?php

namespace Larabookir\Gateway;

use Illuminate\Support\Facades\DB;
use Larabookir\Gateway\Asanpardakht\Asanpardakht;
use Larabookir\Gateway\Exceptions\InvalidRequestException;
use Larabookir\Gateway\Exceptions\NotFoundTransactionException;
use Larabookir\Gateway\Exceptions\PortNotFoundException;
use Larabookir\Gateway\Exceptions\RetryException;
use Larabookir\Gateway\Irankish\Irankish;
use Larabookir\Gateway\Mellat\Mellat;
use Larabookir\Gateway\Parsian\Parsian;
use Larabookir\Gateway\Pasargad\Pasargad;
use Larabookir\Gateway\Payir\Payir;
use Larabookir\Gateway\Paypal\Paypal;
use Larabookir\Gateway\Sadad\Sadad;
use Larabookir\Gateway\Saman\Saman;
use Larabookir\Gateway\Shahr\Shahr;
use Larabookir\Gateway\Zarinpal\Zarinpal;

class GatewayResolver
{
    protected $request;
    protected $config;
    protected $port;

    private const PORT_MAP = [
        Mellat::class => Enum::MELLAT,
        Parsian::class => Enum::PARSIAN,
        Saman::class => Enum::SAMAN,
        Zarinpal::class => Enum::ZARINPAL,
        Sadad::class => Enum::SADAD,
        Asanpardakht::class => Enum::ASANPARDAKHT,
        Paypal::class => Enum::PAYPAL,
        Payir::class => Enum::PAYIR,
        Pasargad::class => Enum::PASARGAD,
        Shahr::class => Enum::SHAHR,
        Irankish::class => Enum::IRANKISH,
    ];

    public function __construct($config = null, $port = null)
    {
        $this->config = app('config');
        $this->request = app('request');

        if ($this->config->has('gateway.timezone')) {
            date_default_timezone_set($this->config->get('gateway.timezone'));
        }

        if ($port !== null) {
            $this->make($port);
        }
    }

    public function getSupportedPorts(): array
    {
        return (array) Enum::getIPGs();
    }

    public function __call($name, $arguments)
    {
        if (in_array(strtoupper($name), $this->getSupportedPorts(), true)) {
            return $this->make($name);
        }

        return call_user_func_array([$this->port, $name], $arguments);
    }

    public function getTable()
    {
        return DB::table($this->config->get('gateway.table'));
    }

    public function verify()
    {
        if (!$this->request->has('transaction_id') && !$this->request->has('iN')) {
            throw new InvalidRequestException;
        }

        $id = $this->request->get('transaction_id', $this->request->get('iN'));
        $transaction = $this->getTable()->whereId($id)->first();

        if (!$transaction) {
            throw new NotFoundTransactionException;
        }

        if (in_array($transaction->status, [Enum::TRANSACTION_SUCCEED, Enum::TRANSACTION_FAILED], true)) {
            throw new RetryException;
        }

        $this->make($transaction->port);

        return $this->port->verify($transaction);
    }

    public function make($port)
    {
        $name = $this->resolvePortName($port);
        
        if ($name === null && in_array(strtoupper($port), $this->getSupportedPorts(), true)) {
            $portName = ucfirst(strtolower($port));
            $name = strtoupper($portName);
            $class = __NAMESPACE__ . '\\' . $portName . '\\' . $portName;
            $port = new $class;
        }

        if ($name === null) {
            throw new PortNotFoundException;
        }

        $this->port = $port;
        $this->port->setConfig($this->config);
        $this->port->setPortName($name);
        $this->port->boot();

        return $this;
    }

    private function resolvePortName($port): ?string
    {
        foreach (self::PORT_MAP as $class => $portName) {
            if ($port instanceof $class) {
                return $portName;
            }
        }

        return null;
    }
}
