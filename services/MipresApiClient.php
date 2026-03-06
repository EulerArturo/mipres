<?php

class MipresApiClient
{
    private $baseUrl;

    public function __construct($baseUrl = 'https://wsmipres.sispro.gov.co/WSSUMMIPRESNOPBS/api')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function get($endpoint, $timeout = 30)
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/json'
        ));

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $data = null;
        $jsonError = null;

        if (!$curlError && $rawResponse !== false && $rawResponse !== '') {
            $decoded = json_decode($rawResponse, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            } else {
                $jsonError = json_last_error_msg();
            }
        }

        return [
            'url' => $url,
            'http_code' => (int) $httpCode,
            'curl_error' => $curlError,
            'raw_response' => $rawResponse,
            'data' => $data,
            'json_error' => $jsonError,
        ];
    }

    public function putJson($endpoint, $payload, $timeout = 30)
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/json'
        ));

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $data = null;
        $jsonError = null;

        if (!$curlError && $rawResponse !== false && $rawResponse !== '') {
            $decoded = json_decode($rawResponse, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            } else {
                $jsonError = json_last_error_msg();
            }
        }

        return [
            'url' => $url,
            'http_code' => (int) $httpCode,
            'curl_error' => $curlError,
            'raw_response' => $rawResponse,
            'data' => $data,
            'json_error' => $jsonError,
        ];
    }
}
