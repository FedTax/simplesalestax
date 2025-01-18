<?php

/**
 * Portions Copyright (c) 2009-2012 The Federal Tax Authority, LLC (FedTax).
 * All Rights Reserved.
 *
 * This file contains Original Code and/or Modifications of Original Code as
 * defined in and that are subject to the FedTax Public Source License (the
 * ‘License’). You may not use this file except in compliance with the License.
 * Please obtain a copy of the License at http://FedTax.net/ftpsl.pdf or
 * http://dev.taxcloud.net/ftpsl/ and read it before using this file.
 *
 * The Original Code and all software distributed under the License are
 * distributed on an ‘AS IS’ basis, WITHOUT WARRANTY OF ANY KIND, EITHER
 * EXPRESS OR IMPLIED, AND FEDTAX  HEREBY DISCLAIMS ALL SUCH WARRANTIES,
 * INCLUDING WITHOUT LIMITATION, ANY WARRANTIES OF MERCHANTABILITY, FITNESS FOR
 * A PARTICULAR PURPOSE, QUIET ENJOYMENT OR NON-INFRINGEMENT.
 *
 * Please see the License for the specific language governing rights and
 * limitations under the License.
 *
 *
 *
 * Modifications made August 20, 2013 by TaxCloud
 * Modifications made May 12, 2023 by Taxcloud
 */

namespace TaxCloud;

use TaxCloud\Exceptions\AddExemptCertificateException;
use TaxCloud\Exceptions\AddTransactionsException;
use TaxCloud\Exceptions\AuthorizedException;
use TaxCloud\Exceptions\AuthorizedWithCaptureException;
use TaxCloud\Exceptions\CapturedException;
use TaxCloud\Exceptions\DeleteExemptCertificateException;
use TaxCloud\Exceptions\GetExemptCertificatesException;
use TaxCloud\Exceptions\GetTICsException;
use TaxCloud\Exceptions\LookupException;
use TaxCloud\Exceptions\PingException;
use TaxCloud\Exceptions\ReturnedException;
use TaxCloud\Exceptions\USPSIDException;
use TaxCloud\Exceptions\VerifyAddressException;
use TaxCloud\Exceptions\RequestException;
use TaxCloud\Exceptions\GetLocationsException;
use TaxCloud\Request\AddExemptCertificate;
use TaxCloud\Request\AddTransactions;
use TaxCloud\Request\Authorized;
use TaxCloud\Request\AuthorizedWithCapture;
use TaxCloud\Request\Captured;
use TaxCloud\Request\DeleteExemptCertificate;
use TaxCloud\Request\GetExemptCertificates;
use TaxCloud\Request\GetLocations;
use TaxCloud\Request\GetTICs;
use TaxCloud\Request\Lookup;
use TaxCloud\Request\LookupForDate;
use TaxCloud\Request\Ping;
use TaxCloud\Request\Returned;
use TaxCloud\Request\VerifyAddress;
use TaxCloud\Response\AddExemptCertificateResponse;
use TaxCloud\Response\AddTransactionsResponse;
use TaxCloud\Response\AuthorizedResponse;
use TaxCloud\Response\AuthorizedWithCaptureResponse;
use TaxCloud\Response\CapturedResponse;
use TaxCloud\Response\DeleteExemptCertificateResponse;
use TaxCloud\Response\GetExemptCertificatesResponse;
use TaxCloud\Response\GetLocationsResponse;
use TaxCloud\Response\GetTICsResponse;
use TaxCloud\Response\LookupResponse;
use TaxCloud\Response\PingResponse;
use TaxCloud\Response\ReturnedResponse;
use TaxCloud\Response\VerifyAddressResponse;
use \JsonSerializable;


/**
 * TaxCloud Web Service
 *
 * @author    Taxcloud
 * @package   php-taxcloud
 */
class Client
{
  /**
   * @var array Default request headers.
   * @since 0.2.0
   */
  protected static $headers = array(
    'Accept: application/json',
    'Content-Type: application/json',
  );

  /**
   * @var string API base URI.
   * @since 1.0.0
   */
  protected $base_uri;

    /**
     * @var string API V3
     */
    protected $base_url_v3 = "https://api.v3.taxcloud.com/tax/connections/";
    /**
     * @var string API V3 without connections
     */
    protected $base_url_without_connections_v3 = "https://api.v3.taxcloud.com/tax/";

  /**
   * Constructor.
   *
   * @since 0.2.0
   *
   * @param $base_uri URI of TaxCloud webservice. (default: 'https://api.taxcloud.net/1.0/TaxCloud/')
   */
  public function __construct($base_uri = "https://api.taxcloud.net/1.0/TaxCloud/")
  {
    $this->base_uri = $base_uri;
  }

    /**
     * Verify that your implementation can communicate with TaxCloud.
     *
     * @param Ping $parameters
     * @return PingResponse
     * @throws PingException
     * @since 0.1.1
     *
     */
  public function Ping(Ping $parameters)
  {
    try {
      $resV3 = $this->getV3("ping",$parameters);
      $resV3 = json_decode($resV3,true);
      if(isset($resV3['status']) && isset($resV3['errors']) ){
          throw new PingException(json_encode($resV3['errors']) );
      }
      if(isset($resV3['code']) && $resV3['code'] !== 200){
          throw new PingException(json_encode($resV3['message']) );
      }
      return TRUE;
    } catch (RequestException $ex) {
      throw new PingException($ex->getMessage());
    }
  }

  /**
   * Inspect and verify a customer provided address to ensure the most accurate
   * tax jurisdiction(s) can be identified.
   *
   * @param  VerifyAddress $parameters
   * @return VerifiedAddress
   */
  public function VerifyAddress(VerifyAddress $parameters)
  {
    try {
      $response = $this->postV3('verify-address', $parameters);
      if(isset($response['status']) && isset($response['errors']) ){
          throw new VerifyAddressException( json_encode($response['errors']) );
      }
      $zip5 = $response['zip'];
      $zip4 = "";
      if(strstr($response['zip'],'-')){
          $tmpZip = explode('-',$response['zip']);
          $zip5 =$tmpZip[0];
          $zip4 =$tmpZip[1];
      }
      $oldFormatResponse = [
          'Address1' => $response['line1'],
          'Address2' => "",
          'City' => $response['city'],
          'State' => $response['state'],
          'Zip5' => $zip5,
          'Zip4' => $zip4,
          'Status' => "",
          'Errors' => ''
      ];
      $result   = new VerifiedAddress(json_encode($oldFormatResponse));
      return $result->getAddress();
    } catch (RequestException $ex) {
      throw new VerifyAddressException($ex->getMessage());
    }
  }

  /**
   * Lookup the applicable tax amounts for items in a cart.
   *
   * @param  Lookup $parameters
   * @return array
   *   An array of cart items.
   *   The top level key of the array is the cart ID so that applications can
   *   verify that this is indeed the cart they are looking for.
   *
   *   Inside that is an array of tax amounts indexed by the cart item index
   *   (which is the line item ID in some applications).
   */
  public function Lookup(Lookup $parameters)
  {
    try {
      $response = $this->postV3('carts', $parameters);
      if(isset($response['status']) && isset($response['errors']) ){
          throw new LookupException( json_encode($response['errors']) );
      }

        $cart_id = $response['items'][0]['cartId'];
        $return = array();
        $tmpTaxArray = [];
        foreach ($response['items'][0]['lineItems'] as $key => $eachItem) {
            $tmpTaxArray[$key] = $eachItem['tax']['amount'];
        }
        $return[$cart_id] = $tmpTaxArray;
        return $return;

    } catch (RequestException $ex) {
      throw new LookupException($ex->getMessage());
    }

  }

  /**
   * Lookup tax amounts using a different date than the inferred today's date
   * for lookup.
   *
   * @param  LookupForDate $parameters
   * @return array
   *   An array of cart items.
   *   The top level key of the array is the cart ID so that applications can
   *   verify that this is indeed the cart they are looking for.
   *
   *   Inside that is an array of tax amounts indexed by the cart item index
   *   (which is the line item ID in some applications).
   */
  public function LookupForDate(LookupForDate $parameters)
  {
    return $this->Lookup($parameters);
  }

  /**
   * Mark an order as authorized (pending payment).
   *
   * @param  Authorized $parameters
   * @return bool
   */
  public function Authorized(Authorized $parameters)
  {
      try {
          $response = $this->postV3('carts/orders', $parameters, false);
          if(isset($response['status']) && isset($response['errors']) ){
              throw new AuthorizedWithCaptureException( json_encode($response['errors']) );
          }
          return TRUE;
      } catch (RequestException $ex) {
          throw new AuthorizedWithCaptureException($ex->getMessage());
      }
  }

  /**
   * Mark a previous Lookup as both Authorized and Captured in a single step - do
   * this AFTER capturing payment with payment processor.
   *
   * @param  AuthorizedWithCapture $parameters
   * @return bool
   */
  public function AuthorizedWithCapture(AuthorizedWithCapture $parameters)
  {
    try {
      $response = $this->postV3('carts/orders', $parameters);
        if(isset($response['status']) && isset($response['errors']) ){
            throw new AuthorizedWithCaptureException( json_encode($response['errors']) );
        }
        return TRUE;
    } catch (RequestException $ex) {
      throw new AuthorizedWithCaptureException($ex->getMessage());
    }
  }

  /**
   * Mark a previous Lookup as Captured - do this AFTER calling Authorized API
   * and after capturing payment with payment processor.
   *
   * @param  Captured $parameters
   * @return bool
   */
  public function Captured(Captured $parameters)
  {
      try {
          $response = $this->postV3('carts/orders', $parameters);
          if(isset($response['status']) && isset($response['errors']) ){
              throw new AuthorizedWithCaptureException( json_encode($response['errors']) );
          }
          return TRUE;
      } catch (RequestException $ex) {
          throw new AuthorizedWithCaptureException($ex->getMessage());
      }
  }

  /**
   * Return a previously Captured transaction. Supports entire order returns as
   * well as individual item returns and even partial item-level returns.
   *
   * @param  Returned $parameters
   * @return true
   */
  public function Returned(Returned $parameters)
  {
    try {
      $response = $this->postV3('refunds', $parameters);
      if ($response->getResponseType() == 'OK') {
        return TRUE;
      } else {
        foreach ($response->getMessages() as $message) {
          throw new ReturnedException($message->getMessage());
        }
      }
    } catch (RequestException $ex) {
      throw new ReturnedException($ex->getMessage());
    }
  }

  /**
   * Save an Entity Exemption Certificate for a given customerID.
   *
   * @param  AddExemptCertificate $parameters
   * @return string CertificateID.
   */
  public function AddExemptCertificate(AddExemptCertificate $parameters)
  {
    try {
      $response = $this->postV3('add-exemption', $parameters);
      if(isset($response['status']) && isset($response['errors']) ){
        throw new AddExemptCertificateException( json_encode($response['errors']) );
      }
      return $response['certificateId'];
    } catch (RequestException $ex) {
      throw new AddExemptCertificateException($ex->getMessage());
    }
  }

  /**
   * Remove a previously saved/created Entity Exemption Certificate for a given
   * customerID.
   *
   * @param  DeleteExemptCertificate $parameters
   * @return bool
   */
  public function DeleteExemptCertificate(DeleteExemptCertificate $parameters)
  {
    try {
      $response = $this->deleteV3('DeleteExemptCertificate', $parameters)  ;
      $res = json_decode($response,true);
      if ( (isset($res['status']) && $res['status'])) {
          throw new DeleteExemptCertificateException($res['detail']);
      } else {
          return TRUE;
      }
    } catch (RequestException $ex) {
      throw new DeleteExemptCertificateException($ex->getMessage());
    }
  }

  /**
   * Get previously saved Entity Exemption Certificates for a given customerID.
   *
   * @param  GetExemptCertificates $parameters
   * @return ExemptCertificate[]
   */
  public function GetExemptCertificates(GetExemptCertificates $parameters)
  {
    try {
       $resV3 = $this->getV3('get-exempt-certificates', $parameters);
       $resV1 = $resV3;
       $response = new GetExemptCertificatesResponse( $resV1 );
       $arrV3 = json_decode($resV3,true);
      if (isset($arrV3['items'])) {
        return $response->getExemptCertificates();
      } else {
        foreach ($response->getMessages() as $message) {
          throw new GetExemptCertificatesException($message->getMessage());
        }
      }
    } catch (RequestException $ex) {
      throw new GetExemptCertificatesException($ex->getMessage());
    }
  }

  /**
   * Get an array of all known Taxability Information Codes (TICs).
   *
   * @param  GetTICs $parameters
   * @return array Array of TIC descriptions, indexed by TIC id.
   */
  public function GetTICs(GetTICs $parameters)
  {
    try {
      $response = new GetTICsResponse($this->post('GetTICs', $parameters));

      if ($response->getResponseType() == 'OK') {
        $return = array();
        foreach ($response->getTICs() as $tic) {
          $return[$tic->getTICID()] = $tic->getDescription();
        }
        return $return;
      } else {
        foreach ($response->getMessages() as $message) {
          throw new GetTICsException($message->getMessage());
        }
      }
    } catch (RequestException $ex) {
      throw new GetTICsException($ex->getMessage());
    }
  }

  /**
   * Get a list of the locations currently associated with the TaxCloud account associated with the API ID being used.
   *
   * @param  GetLocations $parameters
   * @return Location[]
   */
  public function GetLocations(GetLocations $parameters)
  {
    try {
      $response = new GetLocationsResponse($this->post('GetLocations', $parameters));

      if ($response->getResponseType() == 'OK') {
        return $response->getLocations();
      } else {
        foreach ($response->getMessages() as $message) {
          throw new GetLocationsException($message->getMessage());
        }
      }
    } catch (RequestException $ex) {
      throw new GetLocationsException($ex->getMessage());
    }
  }

  /**
   * Add a batch of transactions (up to 25 at a time) from offline sources.
   *
   * All transactions will be imported into TaxCloud, and re-calculated to ensure proper tax amounts are included in
   * subsequent sales tax reports and filings.
   *
   * @param AddTransactions $parameters
   *
   * @return bool Boolean true on success.
   *
   * @throws AddTransactionsException If the AddTransactions request fails.
   */
  public function AddTransactions(AddTransactions $parameters)
  {
    try {
      $response = new AddTransactionsResponse($this->post('AddTransactions', $parameters));

      if ('OK' !== $response->getResponseType()) {
        foreach ($response->getMessages() as $message) {
          throw new AddTransactionsException($message->getMessage());
        }
      }
    } catch (RequestException $ex) {
      throw new AddTransactionsException($ex->getMessage());
    }

    return true;
  }

  /**
   * Send a POST request to a TaxCloud API endpoint.
   *
   * @param string           $endpoint Endpoint name
   * @param JsonSerializable $payload  Request payload
   * @return string Response
   * @throws RequestException If request fails
   */
  protected function post(string $endpoint, JsonSerializable $payload) {
    $url = "{$this->base_uri}{$endpoint}";
    $ch = curl_init($url);

    curl_setopt_array($ch, array(
      CURLOPT_HTTPHEADER => self::$headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => getenv('PHP_TAXCLOUD_REQUEST_TIMEOUT') ?: 30,
      CURLOPT_CAINFO => dirname(dirname(dirname(__FILE__))) . '/cacert.pem',
      CURLOPT_POSTFIELDS => json_encode($payload),
    ));

    try {
      $result = curl_exec($ch);

      if ($result === false) {
        throw new RequestException(curl_error($ch));
      }

      return $result;
    } catch (RequestException $ex) {
      throw $ex;
    } finally {
      curl_close($ch);
    }
  }

    protected function deleteV3(string $endpoint, JsonSerializable $payload)
    {
        $curl = curl_init();
        $xApiKey = $payload->getApiLoginID();
        $params = [];
        $curl = curl_init();
        $connectionId = $payload->getApiKey();
        $certId = $payload->getCertificateID();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->base_url_v3.$connectionId.'/exemption-certificates/'.$certId,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-API-KEY: '.$xApiKey
            ),
        ));

        try {
            $response = curl_exec($curl);
            if (!$response) {
                throw new RequestException(curl_error($curl));
            }

            return $response;
        } catch (RequestException $ex) {
            throw $ex;
        } finally {
            curl_close($curl);
        }

    }
    protected function getV3(string $endpoint, JsonSerializable $payload)
    {
        switch ($endpoint){
            case "get-exempt-certificates":
                $customerId = $payload->getCustomerID();
                $url = 'https://api.v3.taxcloud.com/tax/exemption-certificates?customerId='.$customerId;
                break;
            case "ping":
                $connectionId = $payload->getApiKey();
                $url = "https://api.v3.taxcloud.com/tax/connections/$connectionId/ping";
                break;
        }
        $curl = curl_init();
        $xApiKey = $payload->getApiLoginID();;
        $params = [];
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-API-KEY: '.$xApiKey
            ),
        ));

        try {
            $response = curl_exec($curl);
            if (!$response) {
                throw new RequestException(curl_error($curl));
            }

            return $response;
        } catch (RequestException $ex) {
            throw $ex;
        } finally {
            curl_close($curl);
        }

    }
    /**
     * Send V3 POST request to a TaxCloud API endpoint.
     * @param string $endpoint
     * @param JsonSerializable $payload
     * @return bool|string
     * @throws RequestException
     */
    protected function postV3(string $endpoint, JsonSerializable $payload, $capture=true) {
        $curl = curl_init();
        $params = [];
        switch ($endpoint){
            case "carts":
                $xApiKey = $payload->getApiLoginID();;
                $connectionId = $payload->getApiKey();
                $url = "{$this->base_url_v3}{$connectionId}/{$endpoint}";
                $lineItems = [];
                foreach ($payload->getCartItems() as $key => $eachItem){
                    $lineItems[] = [
                        "index" => $eachItem->getIndex(),
                        "itemId" => (string)$eachItem->getItemID(),
                        "price" => (double)$eachItem->getPrice(),
                        "quantity" => $eachItem->getQty(),
                        "tic" => (int) $eachItem->getTIC()
                    ];
                }
                //customerId can not be empty, if it is guest check, need use customer-0
                $customerId = $payload->getCustomerID();
                if(!$customerId){
                    $customerId = "customer-".$customerId;
                }
                $destinationLine1 = $payload->getDestination()->getAddress1();
                if(!$destinationLine1){
                    $destinationLine1 = "650 street";
                }
                $destinationZip = $payload->getDestination()->getZip5();
                if($payload->getDestination()->getZip4()){
                    $destinationZip .= "-".$payload->getDestination()->getZip4();
                }
                $params = [
                    "items" =>[
                        [
                            "cartId" => $payload->getCartID(),
                            "currency" => [
                                "currencyCode" => "USD"
                            ],
                            "customerId" => (string)$customerId,
                            "deliveredBySeller" => false,
                            "destination" =>[
                                "city" => $payload->getDestination()->getCity(),
                                "countryCode" => "US",
                                "line1" => $destinationLine1,
                                "line2" => $payload->getDestination()->getAddress2(),
                                "state" => $payload->getDestination()->getState(),
                                "zip" => $destinationZip

                            ],
                            "origin" => [
                                "city" => $payload->getOrigin()->getCity(),
                                "countryCode" => "US",
                                "line1" => $payload->getOrigin()->getAddress1(),
                                "line2" => $payload->getOrigin()->getAddress2(),
                                "state" => $payload->getOrigin()->getState(),
                                "zip" => $payload->getOrigin()->getZip5()."-". $payload->getOrigin()->getZip4()
                            ],
                            "lineItems" => $lineItems,
                        ]
                    ]
                ];
                break;
            case "carts/orders":
                $xApiKey = $payload->getApiLoginID();
                $connectionId = $payload->getApiKey();
                $url = "{$this->base_url_v3}{$connectionId}/{$endpoint}";
                $params = [
                    "cartId" => $payload->getCartID(),
                    "completed" => $capture,
                    "orderId" =>  $payload->getOrderID()
                ];
                break;
            case "verify-address":
                $xApiKey = $payload->getApiLoginID();;
                $url = "{$this->base_url_without_connections_v3}/{$endpoint}";
                $destinationLine1 = $payload->getAddress1();
                if(!$destinationLine1){
                    $destinationLine1 = "650 street";
                }
                $params = [
                  "city" => $payload->getCity(),
                  "countryCode" => "US",
                  "line1" => $destinationLine1,
                  "line2" => $payload->getAddress2(),
                  "state"=> $payload->getState(),
                  "zip" =>  $payload->getZip5()
                ];
                break;
            case "refunds":
                $connectionId = $payload->getApiKey();
                $xApiKey = $payload->getApiLoginID();;
                $url = "{$this->base_url_v3}{$connectionId}/orders/{$endpoint}/".$payload->getOrderId();
                $cartItems = $payload->getCartItems();
                $items = [];
                foreach ($cartItems as $each){
                    $items[] = [
                        "itemId" => $each->getItemID(),
                        "quantity" => $each->getQty()
                    ];
                }
                $params = [
                   "items" => $items
                ];
                break;
            case "add-exemption":
                $connectionId = $payload->getApiKey();
                $xApiKey = $payload->getApiLoginID();;
                $states = [];
                $exet = $payload->getExemptCert();
                $detail = $exet->getDetail();
                $excepState = $detail->getExemptStates();
                if($excepState && count($excepState)>0){
                    foreach ($excepState as $eachState){
                        $states[] = [
                            "abbreviation" => $eachState->getStateAbbr(),
                        ];
                    }
                }
                $url = "{$this->base_url_v3}{$connectionId}/exemption-certificates";
                $reason = $detail->getPurchaserExemptionReason();
                $newReasonList = [
                    "FederalGovernmentDepartment" => "FederalGovernment",
                    "StateOrLocalGovernmentName" => "StateOrLocalGovernment",
                    "TribalGovernmentName" => "TribalGovernment",
                    "ForeignDiplomat" => "ForeignDiplomat",
                    "CharitableOrganization" => "CharitableOrganization",
                    "ReligiousOrEducationalOrganization" => "EducationalOrganization",
                    "Resale" => "Resale",
                    "AgriculturalProduction"=> "AgriculturalProduction",
                    "IndustrialProductionOrManufacturing"=> "IndustrialProductionOrManufacturing",
                    "DirectPayPermit"=> "DirectPayPermit",
                    "DirectMail"=> "DirectMail",
                    "Other"=> "Other"
                ];
                $params = [
                    "customerId" => $payload->getCustomerId(),
                    "address" =>[
                        "city" => $detail->getPurchaserCity(),
                        "countryCode" => "US",
                        "line1" => $detail->getPurchaserAddress1(),
                        "line2" => $detail->getPurchaserAddress2(),
                        "state"=> $detail->getPurchaserState(),
                        "zip" =>  $detail->getPurchaserZip()
                    ],
                    "customerBusinessDescription" => $detail->getPurchaserBusinessTypeOtherValue(),
                    "customerBusinessType" => $detail->getPurchaserBusinessType(),
                    "customerName" => $detail->getPurchaserFirstName()." ".$detail->getPurchaserLastName(),
                    "reason" => $newReasonList[$detail->getPurchaserExemptionReason()],
                    "reasonDescription" => $detail->getPurchaserExemptionReasonValue(),
                    "states" => $states
                ];
                break;
        }

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json, application/problem+json',
                'Content-Type: application/json',
                'X-API-KEY: '.$xApiKey
            ),
        ));

        try {
            $response = curl_exec($curl);
            if (!$response) {
                throw new RequestException(curl_error($curl));
            }

            return json_decode( $response , true);
        } catch (RequestException $ex) {
            throw $ex;
        } finally {
            curl_close($curl);
        }
    }

}
