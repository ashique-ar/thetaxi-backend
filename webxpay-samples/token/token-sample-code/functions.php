<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;

use function GuzzleHttp\json_decode;
use function GuzzleHttp\json_encode;

class Api
{
    /** @var GuzzleHttp\Client **/
    private $client;

    function __construct($webxpay_url)
    {
        $this->client = new GuzzleHttp\Client(
            [
                'base_uri' => "$webxpay_url",
                'timeout'  => 100.0,
                'verify' => false
            ]
        );
    }

    // webxpay api calls

    function Auth($username, $password)
    {
        $data = array("username" => "$username", "password" => "$password");

        $response = $this->client->request(
            'POST',
            "auth",
            [
                'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json'],
                'body' => json_encode($data)
            ]
        );

        try {
            $response = json_decode((string) $response->getBody());

            if (isset($response->token)) {
                return $response->token;
            }

            return null;
        } catch (\Throwable $th) {
            return $response;
        }
    }

    function SaveCustomerCard($saveCustomerRequest, $jwt)
    {
        $data = $saveCustomerRequest;

        $response = $this->client->request(
            'POST',
            "cards/save3ds",
            [
                'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => "Bearer $jwt"],
                'body' => json_encode($data)
            ]
        );

        $response = json_decode((string) $response->getBody());

        return $response;
    }

    function GetAvailableGateways($currency, $jwt)
    {
        $response = $this->client->request(
            'GET',
            'cards/other/' . $currency,
            [
                'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => "Bearer $jwt"],
            ]
        );

        $response = json_decode(json_decode((string) $response->getBody()));

        return $response;
    }

    function GetCustomerCards($customerCardRequest, $jwt)
    {
        $data = $customerCardRequest;

        $response = $this->client->request(
            'POST',
            "cards",
            [
                'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => "Bearer $jwt"],
                'body' => json_encode($data)
            ]
        );

        $response = json_decode((string) $response->getBody());

        return $response;
    }

    function PayFromCard($payFromCardRequest, $jwt)
    {
        $data = $payFromCardRequest;

        $response = $this->client->request(
            'POST',
            "cards/pay/",
            [
                'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => "Bearer $jwt"],
                'body' => json_encode($data)
            ]
        );

        $response = json_decode((string) $response->getBody());

        return $response;
    }

    function PayFromCard3ds($payFromCardRequest, $jwt)
    {
        $data = $payFromCardRequest;

        $response = $this->client->request(
            'POST',
            "cards/pay/token3ds",
            [
                'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => "Bearer $jwt"],
                'body' => json_encode($data)
            ]
        );

        $response = json_decode((string) $response->getBody());

        return $response;
    }

    function PayFromSession3ds($payFromCardRequest, $jwt)
    {
        $data = $payFromCardRequest;

        $response = $this->client->request(
            'POST',
            "cards/pay/session3ds",
            [
                'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => "Bearer $jwt"],
                'body' => json_encode($data)
            ]
        );

        $response = json_decode((string) $response->getBody());

        return $response;
    }

    function GetUserDetails($jwt)
    {
        $response = $this->client->request(
            'GET',
            "merchant/user",
            [
                'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => "Bearer $jwt"]
            ]
        );

        $response = json_decode((string) $response->getBody());

        return $response;
    }

    function DeleteCard($deleteCardRequest, $jwt)
    {
        $data = $deleteCardRequest;

        $response = $this->client->request(
            'DELETE',
            "cards",
            [
                'headers'  => ['content-type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => "Bearer $jwt"],
                'body' => json_encode($data)
            ]
        );

        $response = json_decode((string) $response->getBody());

        return $response;
    }
}
