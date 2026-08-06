<?php

namespace App\Services\Printify;

use App\Models\PrintifyAccount;
use Illuminate\Http\Client\Factory as HttpFactory;

class PrintifyClientFactory
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Builds an immutable client scoped to one account. This is the only place
     * an account's api_key is decrypted for outbound use.
     */
    public function for(PrintifyAccount $account): PrintifyClient
    {
        return new PrintifyClient($this->http, $account->api_key);
    }
}
