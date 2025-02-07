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
        if(isset($response['status']) && isset($response['errors']) ){
            throw new ReturnedException( json_encode($response['errors'])  );
        }
        return TRUE;
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
        $return = array();
        $tmpArr = array ( 0 => array ( 'TICID' => 0, 'Description' => 'Uncategorized', ), 1 => array ( 'TICID' => 10000, 'Description' => 'Administrative', ), 2 => array ( 'TICID' => 10001, 'Description' => 'Shipping', ), 3 => array ( 'TICID' => 10005, 'Description' => 'Gift card', ), 4 => array ( 'TICID' => 10010, 'Description' => 'Charges by the seller for any services necessary to complete the sale other than delivery and installation', ), 5 => array ( 'TICID' => 10011, 'Description' => 'Credit card processing or transaction fee', ), 6 => array ( 'TICID' => 10040, 'Description' => 'Installation charges', ), 7 => array ( 'TICID' => 10060, 'Description' => 'Value of trade-in ', ), 8 => array ( 'TICID' => 10061, 'Description' => 'Trade-ins of like-kind property', ), 9 => array ( 'TICID' => 10062, 'Description' => 'Trade-ins of non-like kind property', ), 10 => array ( 'TICID' => 10063, 'Description' => 'Trade-ins of motor vehicles', ), 11 => array ( 'TICID' => 10064, 'Description' => 'Trade-ins of watercraft on watercraft', ), 12 => array ( 'TICID' => 10065, 'Description' => 'Trade-ins of watercraft and trailer or outboard motor', ), 13 => array ( 'TICID' => 10070, 'Description' => 'Telecommunication nonrecurring charges', ), 14 => array ( 'TICID' => 10080, 'Description' => 'Employee discounts that are reimbursed by a third party on sales of motor vehicles.', ), 15 => array ( 'TICID' => 10085, 'Description' => 'Manufacturer rebates on motor vehicles.', ), 16 => array ( 'TICID' => 10090, 'Description' => 'All coupons issued by a manufacturer, supplier, or distributor of a product(s) that entitle the purchaser to a reduction in sales price and allowed by the seller who is reimbursed by the manufacturer, supplier or distributor.', ), 17 => array ( 'TICID' => 11000, 'Description' => 'Handling, crating, packing, preparation for mailing or delivery, and similar charges', ), 18 => array ( 'TICID' => 11010, 'Description' => 'Transportation, shipping, postage, and similar charges', ), 19 => array ( 'TICID' => 11011, 'Description' => 'Transportation, shipping, postage, and similar charges by USPS', ), 20 => array ( 'TICID' => 11012, 'Description' => 'Transportation, shipping, postage, with pick-up option', ), 21 => array ( 'TICID' => 11013, 'Description' => 'Transportation, shipping, postage, and similar charges where the charge is marked up.', ), 22 => array ( 'TICID' => 11014, 'Description' => 'Inbound freight', ), 23 => array ( 'TICID' => 11015, 'Description' => 'Delivery charges involving or related to the sale of electricity, natural gas, or artificial gas by a utility', ), 24 => array ( 'TICID' => 11020, 'Description' => 'Handling, crating, packing, preparation for mailing or delivery, and similar charges for direct mail ', ), 25 => array ( 'TICID' => 11021, 'Description' => 'Transportation, shipping, and similar charges for direct mail', ), 26 => array ( 'TICID' => 11022, 'Description' => 'Postage for direct mail', ), 27 => array ( 'TICID' => 11097, 'Description' => 'Minnesota Retail Delivery Fee', ), 28 => array ( 'TICID' => 11098, 'Description' => 'Colorado Retail Delivery Fees', ), 29 => array ( 'TICID' => 11099, 'Description' => 'Postage/Delivery', ), 30 => array ( 'TICID' => 11100, 'Description' => 'Direct-mail related', ), 31 => array ( 'TICID' => 11110, 'Description' => 'Seller State Responsible', ), 32 => array ( 'TICID' => 11120, 'Description' => 'Seller Tribal Responsible', ), 33 => array ( 'TICID' => 20000, 'Description' => 'Clothing', ), 34 => array ( 'TICID' => 20010, 'Description' => 'Clothing', ), 35 => array ( 'TICID' => 20011, 'Description' => 'Diapers - Adult', ), 36 => array ( 'TICID' => 20012, 'Description' => 'Diapers - Children', ), 37 => array ( 'TICID' => 20013, 'Description' => 'Work Boots', ), 38 => array ( 'TICID' => 20015, 'Description' => 'Essential clothing priced below a state specific threshold', ), 39 => array ( 'TICID' => 20020, 'Description' => 'Clothing accessories or equipment', ), 40 => array ( 'TICID' => 20021, 'Description' => 'Sunglasses - non-prescription', ), 41 => array ( 'TICID' => 20030, 'Description' => 'Protective equipment', ), 42 => array ( 'TICID' => 20040, 'Description' => 'Sport or recreational equipment', ), 43 => array ( 'TICID' => 20041, 'Description' => 'Goggles, snorkels, swim masks', ), 44 => array ( 'TICID' => 20042, 'Description' => 'Life jackets', ), 45 => array ( 'TICID' => 20050, 'Description' => 'Fur clothing', ), 46 => array ( 'TICID' => 20060, 'Description' => 'Energy star qualified product', ), 47 => array ( 'TICID' => 20070, 'Description' => 'School supply', ), 48 => array ( 'TICID' => 20080, 'Description' => 'School art supply', ), 49 => array ( 'TICID' => 20090, 'Description' => 'School instructional material', ), 50 => array ( 'TICID' => 20091, 'Description' => 'Graphing calculators', ), 51 => array ( 'TICID' => 20100, 'Description' => 'School computer supply', ), 52 => array ( 'TICID' => 20105, 'Description' => 'WaterSense Products', ), 53 => array ( 'TICID' => 20106, 'Description' => 'Water-Conserving Products', ), 54 => array ( 'TICID' => 20110, 'Description' => 'Computers', ), 55 => array ( 'TICID' => 20120, 'Description' => 'Prewritten computer software', ), 56 => array ( 'TICID' => 20130, 'Description' => 'Clothing, except baby receiving blankets', ), 57 => array ( 'TICID' => 20131, 'Description' => 'Baby Receiving Blankets', ), 58 => array ( 'TICID' => 20150, 'Description' => 'All Disaster preparedness supplies', ), 59 => array ( 'TICID' => 20160, 'Description' => 'Disaster preparedness general supply', ), 60 => array ( 'TICID' => 20161, 'Description' => 'Disaster preparedness portable generators and power cords', ), 61 => array ( 'TICID' => 20162, 'Description' => 'Portable self-powered light sources', ), 62 => array ( 'TICID' => 20163, 'Description' => 'Portable self-powered radios, two-way radios, or weather-band radios', ), 63 => array ( 'TICID' => 20164, 'Description' => 'Batteries, including rechargeable batteries', ), 64 => array ( 'TICID' => 20165, 'Description' => 'Gas or diesel fuel tanks', ), 65 => array ( 'TICID' => 20166, 'Description' => 'Nonelectric food storage coolers', ), 66 => array ( 'TICID' => 20167, 'Description' => 'Portable power banks', ), 67 => array ( 'TICID' => 20168, 'Description' => 'Hurricane Shutters', ), 68 => array ( 'TICID' => 20170, 'Description' => 'Disaster preparedness safety supply', ), 69 => array ( 'TICID' => 20171, 'Description' => 'Smoke detectors or smoke alarms', ), 70 => array ( 'TICID' => 20172, 'Description' => 'Fire extinguishers', ), 71 => array ( 'TICID' => 20173, 'Description' => 'Carbon monoxide detectors', ), 72 => array ( 'TICID' => 20174, 'Description' => 'First aid kits', ), 73 => array ( 'TICID' => 20180, 'Description' => 'Disaster preparedness food-related supply', ), 74 => array ( 'TICID' => 20181, 'Description' => 'Manual can opener', ), 75 => array ( 'TICID' => 20182, 'Description' => 'Reusable ice', ), 76 => array ( 'TICID' => 20190, 'Description' => 'Disaster preparedness fastening supply', ), 77 => array ( 'TICID' => 20191, 'Description' => 'Tarpaulins or other flexible waterproof sheeting', ), 78 => array ( 'TICID' => 20192, 'Description' => 'Ground anchor systems', ), 79 => array ( 'TICID' => 20200, 'Description' => 'Pet Related Disaster Preparedness Supplies', ), 80 => array ( 'TICID' => 20201, 'Description' => 'Wet dog or cat food if sold individually in a can or pouch or the equivalent if sold in a box or case', ), 81 => array ( 'TICID' => 20202, 'Description' => 'Dry dog or cat food weighing 50 or fewer pounds', ), 82 => array ( 'TICID' => 20203, 'Description' => 'Collapsible or travel-sized food or water bowls for pets', ), 83 => array ( 'TICID' => 20204, 'Description' => 'Cat litter pans', ), 84 => array ( 'TICID' => 20205, 'Description' => 'Pet waste disposal bags', ), 85 => array ( 'TICID' => 20206, 'Description' => 'Hamster or rabbit substrate', ), 86 => array ( 'TICID' => 20207, 'Description' => 'Leashes, collars, and muzzles for pets', ), 87 => array ( 'TICID' => 20208, 'Description' => 'Pet pads', ), 88 => array ( 'TICID' => 20209, 'Description' => 'Cat litter weighing 25 or fewer pounds', ), 89 => array ( 'TICID' => 20210, 'Description' => 'Pet beds', ), 90 => array ( 'TICID' => 20211, 'Description' => 'Portable pet kennels or pet carriers', ), 91 => array ( 'TICID' => 20300, 'Description' => 'Work gloves', ), 92 => array ( 'TICID' => 20301, 'Description' => 'Safety glasses', ), 93 => array ( 'TICID' => 20302, 'Description' => 'Protective coveralls', ), 94 => array ( 'TICID' => 20303, 'Description' => 'Hearing protection items', ), 95 => array ( 'TICID' => 20304, 'Description' => 'Tool belts', ), 96 => array ( 'TICID' => 20305, 'Description' => 'Hard hats and other head protection', ), 97 => array ( 'TICID' => 20306, 'Description' => 'High-visibility safety vests', ), 98 => array ( 'TICID' => 21000, 'Description' => 'Other Sales Tax Holiday Items', ), 99 => array ( 'TICID' => 21001, 'Description' => 'Energy Star dishwasher', ), 100 => array ( 'TICID' => 21002, 'Description' => 'Energy Star clothes washer', ), 101 => array ( 'TICID' => 21003, 'Description' => 'Energy Star clothes dryer', ), 102 => array ( 'TICID' => 21004, 'Description' => 'Energy Star air conditioner', ), 103 => array ( 'TICID' => 21005, 'Description' => 'Energy Star ceiling fan', ), 104 => array ( 'TICID' => 21006, 'Description' => 'Energy Star light bulb', ), 105 => array ( 'TICID' => 21007, 'Description' => 'Energy Star dehumidifier', ), 106 => array ( 'TICID' => 21008, 'Description' => 'Energy Star programmable thermostat', ), 107 => array ( 'TICID' => 21009, 'Description' => 'Energy Star refrigerator', ), 108 => array ( 'TICID' => 21010, 'Description' => 'Energy Star freezer', ), 109 => array ( 'TICID' => 21011, 'Description' => 'Energy Star water heater - not solar', ), 110 => array ( 'TICID' => 21012, 'Description' => 'Energy Star water heater - solar', ), 111 => array ( 'TICID' => 21013, 'Description' => 'Energy Star conventional ovens, ranges & stoves', ), 112 => array ( 'TICID' => 21014, 'Description' => 'Energy Star trash compactors', ), 113 => array ( 'TICID' => 21015, 'Description' => 'Energy Star furnaces', ), 114 => array ( 'TICID' => 21016, 'Description' => 'Energy Star heat pump', ), 115 => array ( 'TICID' => 21017, 'Description' => 'Energy Star boiler', ), 116 => array ( 'TICID' => 21018, 'Description' => 'WaterSense bathroom sink', ), 117 => array ( 'TICID' => 21019, 'Description' => 'WaterSense faucet accessories', ), 118 => array ( 'TICID' => 21020, 'Description' => 'WaterSense showerhead', ), 119 => array ( 'TICID' => 21021, 'Description' => 'WaterSense toilet', ), 120 => array ( 'TICID' => 21022, 'Description' => 'WaterSense urinal', ), 121 => array ( 'TICID' => 21023, 'Description' => 'WaterSense landscape irrigation controllers', ), 122 => array ( 'TICID' => 21100, 'Description' => 'Impact-Resistant Doors, Garage Doors, and Windows', ), 123 => array ( 'TICID' => 21200, 'Description' => 'Industry textbooks and code books', ), 124 => array ( 'TICID' => 21201, 'Description' => 'Power tool batteries', ), 125 => array ( 'TICID' => 21202, 'Description' => 'Handheld pipe cutters', ), 126 => array ( 'TICID' => 21203, 'Description' => 'Drain opening tools', ), 127 => array ( 'TICID' => 21204, 'Description' => 'Plumbing inspection equipment', ), 128 => array ( 'TICID' => 21205, 'Description' => 'Ladders', ), 129 => array ( 'TICID' => 21206, 'Description' => 'Power tools', ), 130 => array ( 'TICID' => 21207, 'Description' => 'Tool boxes for vehicles', ), 131 => array ( 'TICID' => 21208, 'Description' => 'Hand tools', ), 132 => array ( 'TICID' => 21209, 'Description' => 'Tool boxes', ), 133 => array ( 'TICID' => 21210, 'Description' => 'Shovels and rakes', ), 134 => array ( 'TICID' => 21211, 'Description' => 'LED flashlights', ), 135 => array ( 'TICID' => 21212, 'Description' => 'Electrical voltage and testing equipment', ), 136 => array ( 'TICID' => 21213, 'Description' => 'Shop lights', ), 137 => array ( 'TICID' => 21214, 'Description' => 'Duffle bags or tote bags', ), 138 => array ( 'TICID' => 21215, 'Description' => 'Gas-powered chainsaws', ), 139 => array ( 'TICID' => 21216, 'Description' => 'Chainsaw accessories', ), 140 => array ( 'TICID' => 21300, 'Description' => 'Fishing supplies', ), 141 => array ( 'TICID' => 21301, 'Description' => 'Bait or fishing tackle', ), 142 => array ( 'TICID' => 21302, 'Description' => 'Tackle boxes or bags', ), 143 => array ( 'TICID' => 21303, 'Description' => 'Fishing rods', ), 144 => array ( 'TICID' => 21304, 'Description' => 'Fishing reels', ), 145 => array ( 'TICID' => 21305, 'Description' => 'Fishing rod and reel set', ), 146 => array ( 'TICID' => 21310, 'Description' => 'Camping supplies', ), 147 => array ( 'TICID' => 21311, 'Description' => 'Camping lanterns', ), 148 => array ( 'TICID' => 21312, 'Description' => 'Flashlights', ), 149 => array ( 'TICID' => 21313, 'Description' => 'Sleeping bags', ), 150 => array ( 'TICID' => 21314, 'Description' => 'Portable hammocks', ), 151 => array ( 'TICID' => 21315, 'Description' => 'Camping Stoves', ), 152 => array ( 'TICID' => 21316, 'Description' => 'Collapsible camping chairs', ), 153 => array ( 'TICID' => 21317, 'Description' => 'Tents', ), 154 => array ( 'TICID' => 21320, 'Description' => 'Boating and water activity supplies', ), 155 => array ( 'TICID' => 21321, 'Description' => 'Inflatable pool tubes, pool floats; inflatable chairs, pool toys ', ), 156 => array ( 'TICID' => 21322, 'Description' => 'Safety flares', ), 157 => array ( 'TICID' => 21324, 'Description' => 'Oars, paddles', ), 158 => array ( 'TICID' => 21325, 'Description' => 'Kneeboards, wakeboards, water skis, and inflatable tubes or floats capable of being towed', ), 159 => array ( 'TICID' => 21326, 'Description' => 'Paddleboards, surfboards', ), 160 => array ( 'TICID' => 21327, 'Description' => 'Canoes, kayaks', ), 161 => array ( 'TICID' => 21330, 'Description' => 'Residential Pool Supplies', ), 162 => array ( 'TICID' => 21331, 'Description' => 'Individual residential pool & spa replacement parts, nets, filters, lights, covers', ), 163 => array ( 'TICID' => 21332, 'Description' => 'Residential pool & spa chemicals purchased by an individual', ), 164 => array ( 'TICID' => 21350, 'Description' => 'General outdoor equipment and supplies', ), 165 => array ( 'TICID' => 21351, 'Description' => 'Insect repellant', ), 166 => array ( 'TICID' => 21352, 'Description' => 'Water bottles', ), 167 => array ( 'TICID' => 21353, 'Description' => 'Hydration packs', ), 168 => array ( 'TICID' => 21354, 'Description' => 'Binoculars', ), 169 => array ( 'TICID' => 21355, 'Description' => 'Outdoor gas or charcoal grills', ), 170 => array ( 'TICID' => 21356, 'Description' => 'Bicycles', ), 171 => array ( 'TICID' => 21357, 'Description' => 'Electric scooters weighing less than 75 pounds', ), 172 => array ( 'TICID' => 22000, 'Description' => 'Bed & bath products', ), 173 => array ( 'TICID' => 30000, 'Description' => 'Computers, Electronics, and Appliances', ), 174 => array ( 'TICID' => 30015, 'Description' => 'Non-prewritten (custom) computer software', ), 175 => array ( 'TICID' => 30025, 'Description' => 'Non-prewritten (custom) computer software delivered electronically', ), 176 => array ( 'TICID' => 30035, 'Description' => 'Non-prewritten (custom) computer software delivered via load and leave Mandatory computer software maintenance contracts', ), 177 => array ( 'TICID' => 30040, 'Description' => 'Prewritten computer software', ), 178 => array ( 'TICID' => 30050, 'Description' => 'Prewritten computer software delivered electronically', ), 179 => array ( 'TICID' => 30060, 'Description' => 'Prewritten computer software delivered via load and leave', ), 180 => array ( 'TICID' => 30070, 'Description' => 'Remotely Accessed Prewritten Software', ), 181 => array ( 'TICID' => 30100, 'Description' => 'Computer', ), 182 => array ( 'TICID' => 30101, 'Description' => 'Bulk sales of computers', ), 183 => array ( 'TICID' => 30200, 'Description' => 'Mandatory computer software maintenance contracts with respect to prewritten computer software', ), 184 => array ( 'TICID' => 30210, 'Description' => 'Mandatory computer software maintenance contracts with respect to prewritten computer software which is delivered electronically', ), 185 => array ( 'TICID' => 30220, 'Description' => 'Mandatory computer software maintenance contracts with respect to prewritten computer software which is delivered via load and leave', ), 186 => array ( 'TICID' => 30230, 'Description' => 'Mandatory computer software maintenance contracts with respect to non-prewritten (custom) computer software', ), 187 => array ( 'TICID' => 30240, 'Description' => 'Mandatory computer software maintenance contracts with respect to non-prewritten (custom) software which is delivered electronically', ), 188 => array ( 'TICID' => 30250, 'Description' => 'Mandatory computer software maintenance contracts with respect to non-prewritten (custom) software which is delivered via load and leave', ), 189 => array ( 'TICID' => 30300, 'Description' => 'Optional computer software maintenance contracts with respect to prewritten computer software that only provide updates or upgrades with respect to the software', ), 190 => array ( 'TICID' => 30310, 'Description' => 'Optional computer software maintenance contracts with respect to prewritten computer software that only provide updates or upgrades delivered electronically with respect to the software', ), 191 => array ( 'TICID' => 30320, 'Description' => 'Optional computer software maintenance contracts with respect to prewritten computer software that only provide updates or upgrades delivered via load and leave with respect to the software', ), 192 => array ( 'TICID' => 30330, 'Description' => 'Optional computer software maintenance contracts with respect to non-prewritten (custom) computer software that only provide updates or upgrades with respect to the software', ), 193 => array ( 'TICID' => 30340, 'Description' => 'Optional computer software maintenance contracts with respect to non-prewritten (custom) computer software that only provide updates or upgrades delivered electronically with respect to the software', ), 194 => array ( 'TICID' => 30350, 'Description' => 'Optional computer software maintenance contracts with respect to non-prewritten (custom) computer software that only provide updates or upgrades delivered via load and leave with respect to the software', ), 195 => array ( 'TICID' => 30360, 'Description' => 'Optional computer software maintenance contracts with respect to non-prewritten (custom) computer software that only provide support services to the software', ), 196 => array ( 'TICID' => 30370, 'Description' => 'Optional computer software maintenance contracts with respect to non-prewritten (custom) computer software that provide updates or upgrades and support services to the softwareAppendix E', ), 197 => array ( 'TICID' => 30380, 'Description' => 'Optional computer software maintenance contracts with respect to non-prewritten (custom) computer software that provide updates or upgrades delivered electronically and support services to the software', ), 198 => array ( 'TICID' => 30390, 'Description' => 'Optional computer software maintenance contracts with respect to non-prewritten (custom) computer software provide updates or upgrades delivered via load and leave and support services to the software', ), 199 => array ( 'TICID' => 30400, 'Description' => 'Optional computer software maintenance contracts with respect to prewritten computer software that provide updates or upgrades and support services to the software', ), 200 => array ( 'TICID' => 30410, 'Description' => 'Optional computer software maintenance contracts with respect to prewritten computer software that provide updates and upgrades delivered electronically and support services to the software', ), 201 => array ( 'TICID' => 30420, 'Description' => 'Optional computer software maintenance contracts with respect to prewritten computer software that provide updates and upgrades delivered via load and leave and support services to the software', ), 202 => array ( 'TICID' => 30430, 'Description' => 'Optional computer software maintenance contracts with respect to prewritten computer software that only provide support services to the software', ), 203 => array ( 'TICID' => 31000, 'Description' => 'Products Transferred Electronically', ), 204 => array ( 'TICID' => 31035, 'Description' => 'Audio-Visual Works', ), 205 => array ( 'TICID' => 31040, 'Description' => 'Digital Audio Visual Works (with rights for permanent use)', ), 206 => array ( 'TICID' => 31050, 'Description' => 'Digital Audio Visual Works (with rights of less than permanent use)', ), 207 => array ( 'TICID' => 31060, 'Description' => 'Digital Audio Visual Works (with rights conditioned on continued payments)', ), 208 => array ( 'TICID' => 31065, 'Description' => 'Digital Audio Visual Works sold to users other than the end user', ), 209 => array ( 'TICID' => 31069, 'Description' => 'Audio Works', ), 210 => array ( 'TICID' => 31070, 'Description' => 'Digital Audio Works (with rights for permanent use)', ), 211 => array ( 'TICID' => 31080, 'Description' => 'Digital Audio Works (with rights of less than permanent use)', ), 212 => array ( 'TICID' => 31090, 'Description' => 'Digital Audio Works (with rights conditioned on continued payments)', ), 213 => array ( 'TICID' => 31095, 'Description' => 'Digital Audio Works sold to users other than the end user', ), 214 => array ( 'TICID' => 31099, 'Description' => 'Digital Books', ), 215 => array ( 'TICID' => 31100, 'Description' => 'Digital Books (with rights for permanent use)', ), 216 => array ( 'TICID' => 31110, 'Description' => 'Digital Books (with rights of less than permanent use)', ), 217 => array ( 'TICID' => 31120, 'Description' => 'Digital Books (with rights conditioned on continued payments)', ), 218 => array ( 'TICID' => 31121, 'Description' => 'Subscriptions to products transferred electronically', ), 219 => array ( 'TICID' => 31125, 'Description' => 'Digital Books sold to users other than the end user', ), 220 => array ( 'TICID' => 32000, 'Description' => 'Digital textbooks', ), 221 => array ( 'TICID' => 40000, 'Description' => 'Foods and Beverages', ), 222 => array ( 'TICID' => 40010, 'Description' => 'Candy', ), 223 => array ( 'TICID' => 40015, 'Description' => 'Dried or partially dried fruit', ), 224 => array ( 'TICID' => 40020, 'Description' => 'Dietary Supplements', ), 225 => array ( 'TICID' => 40030, 'Description' => 'Food and food ingredients excluding alcoholic beverages and tobacco', ), 226 => array ( 'TICID' => 40031, 'Description' => 'Seeds and plants for use in gardens to produce food for the personal consumption of a household', ), 227 => array ( 'TICID' => 40040, 'Description' => 'Food sold through vending machines', ), 228 => array ( 'TICID' => 40050, 'Description' => 'Soft Drinks', ), 229 => array ( 'TICID' => 40060, 'Description' => 'Bottled Water', ), 230 => array ( 'TICID' => 40080, 'Description' => 'Gift basket with only food, or only food and candy, candy is 50% or less but greater than 10%', ), 231 => array ( 'TICID' => 40081, 'Description' => 'Gift basket with only food, or only food and candy, candy is 10% or less', ), 232 => array ( 'TICID' => 40082, 'Description' => 'Gift basket with only food, or only food and candy, candy is more than 50%', ), 233 => array ( 'TICID' => 40083, 'Description' => 'Gift basket with food, candy, and non-food items, food is less than 50%, candy is less than 90%, non-food items are greater than 10% but less than 50%', ), 234 => array ( 'TICID' => 40084, 'Description' => 'Gift basket with food, candy, and non-food items, food is less than 50%, candy is between 1-99%, non-food items are 10% or less', ), 235 => array ( 'TICID' => 40085, 'Description' => 'Gift basket with food, candy, and non-food items, food is greater than 75%, candy is less than 25%, non-food items are 10% or less', ), 236 => array ( 'TICID' => 40086, 'Description' => 'Gift basket with food, candy, and non-food items, food is 90% or more, candy is less than 10%, non-food items are less than 10%', ), 237 => array ( 'TICID' => 40087, 'Description' => 'Gift basket with popcorn and candy, popcorn is 50% or more but less than 90%', ), 238 => array ( 'TICID' => 40088, 'Description' => 'Gift basket with popcorn or candy, candy is 50% or more', ), 239 => array ( 'TICID' => 40089, 'Description' => 'Gift basket with food and non-food items, food is 90% or more', ), 240 => array ( 'TICID' => 40090, 'Description' => 'Gift basket with food and non-food items, food is more than 50% but less than 90%', ), 241 => array ( 'TICID' => 41000, 'Description' => 'Prepared Food', ), 242 => array ( 'TICID' => 41010, 'Description' => 'Food sold without eating utensils provided by the seller whose primary NAICS classification is manufacturing in sector 311, except subsector 3118 (bakeries)', ), 243 => array ( 'TICID' => 41020, 'Description' => 'Food sold without eating utensils provided by the seller in an unheated state by weight or volume as a single item', ), 244 => array ( 'TICID' => 41025, 'Description' => 'Deli Meats & Seafood', ), 245 => array ( 'TICID' => 41030, 'Description' => 'Bakery items sold without eating utensils provided by the seller, including bread, rolls, buns, biscuits, bagels, croissants, pastries, donuts, Danish, cakes, tortes, pies, tarts, muffins, bars, cookies, tortillas ', ), 246 => array ( 'TICID' => 41040, 'Description' => 'Prpared uncooked food', ), 247 => array ( 'TICID' => 41041, 'Description' => 'Prepared food that can only be consumed off-premises', ), 248 => array ( 'TICID' => 41045, 'Description' => 'Bottled water, candy, dietary supplements, soft drinks and items considered prepared food when utensils are made availabe to the customer', ), 249 => array ( 'TICID' => 50000, 'Description' => 'Medical Related', ), 250 => array ( 'TICID' => 51000, 'Description' => 'Drugs/Pharmaceuticals', ), 251 => array ( 'TICID' => 51001, 'Description' => 'Human use', ), 252 => array ( 'TICID' => 51002, 'Description' => 'Animal/Veterinary use ', ), 253 => array ( 'TICID' => 51010, 'Description' => 'Drugs for human use without a prescription', ), 254 => array ( 'TICID' => 51020, 'Description' => 'Drugs, other than over-the-counter drugs, for human use with a prescription', ), 255 => array ( 'TICID' => 51030, 'Description' => 'Drugs for animal use without a prescription', ), 256 => array ( 'TICID' => 51040, 'Description' => 'Drugs for animal use with a prescription', ), 257 => array ( 'TICID' => 51050, 'Description' => 'Insulin for human use without a prescription', ), 258 => array ( 'TICID' => 51055, 'Description' => 'Insulin', ), 259 => array ( 'TICID' => 51060, 'Description' => 'Insulin for human use with a prescription', ), 260 => array ( 'TICID' => 51070, 'Description' => 'Insulin for animal use without a prescription', ), 261 => array ( 'TICID' => 51075, 'Description' => 'Insulin', ), 262 => array ( 'TICID' => 51080, 'Description' => 'Insulin for animal use with a prescription', ), 263 => array ( 'TICID' => 51090, 'Description' => 'Medical oxygen for human use without a prescription', ), 264 => array ( 'TICID' => 51095, 'Description' => 'Oxygen', ), 265 => array ( 'TICID' => 51100, 'Description' => 'Medical oxygen for human use with a prescription', ), 266 => array ( 'TICID' => 51110, 'Description' => 'Medical oxygen for animal use without a prescription', ), 267 => array ( 'TICID' => 51115, 'Description' => 'Oxygen', ), 268 => array ( 'TICID' => 51120, 'Description' => 'Medical oxygen for animal use with a prescription', ), 269 => array ( 'TICID' => 51130, 'Description' => 'Over-the-counter drugs for human use without a prescription', ), 270 => array ( 'TICID' => 51135, 'Description' => 'Over-the-counter', ), 271 => array ( 'TICID' => 51140, 'Description' => 'Over-the-counter drugs for human use with a prescription', ), 272 => array ( 'TICID' => 51150, 'Description' => 'Over-the-counter drugs for animal use without a prescription', ), 273 => array ( 'TICID' => 51155, 'Description' => 'Over-the-counter', ), 274 => array ( 'TICID' => 51160, 'Description' => 'Over-the-counter drugs for animal use with a prescription', ), 275 => array ( 'TICID' => 51170, 'Description' => 'Grooming and hygiene products for human use', ), 276 => array ( 'TICID' => 51171, 'Description' => 'Grooming and hygiene products for human use', ), 277 => array ( 'TICID' => 51172, 'Description' => 'Grooming and hygiene products for human use', ), 278 => array ( 'TICID' => 51173, 'Description' => 'Hand soap, bar soap, and body wash', ), 279 => array ( 'TICID' => 51174, 'Description' => 'Sunscreen and sunblock', ), 280 => array ( 'TICID' => 51175, 'Description' => 'Menstrual Discharge Collection Devices, also known as Feminine Hygiene Products', ), 281 => array ( 'TICID' => 51176, 'Description' => 'Period underwear', ), 282 => array ( 'TICID' => 51177, 'Description' => 'Menstrual discharge collection devices that are clothing', ), 283 => array ( 'TICID' => 51180, 'Description' => 'Grooming and hygiene products for animal use', ), 284 => array ( 'TICID' => 51190, 'Description' => 'Drugs for human use to hospitals ', ), 285 => array ( 'TICID' => 51195, 'Description' => 'Drugs for human use to other medical facilities', ), 286 => array ( 'TICID' => 51200, 'Description' => 'Prescription drugs for human use to hospitals ', ), 287 => array ( 'TICID' => 51205, 'Description' => 'Prescription drugs for human use to other medical facilities', ), 288 => array ( 'TICID' => 51210, 'Description' => 'Drugs for animal use to veterinary hospitals and other animal medical facilities', ), 289 => array ( 'TICID' => 51220, 'Description' => 'Prescription drugs for animal use to hospitals and other animal medical facilities', ), 290 => array ( 'TICID' => 51240, 'Description' => 'Free samples of drugs for human use', ), 291 => array ( 'TICID' => 51245, 'Description' => 'Free Samples', ), 292 => array ( 'TICID' => 51250, 'Description' => 'Free samples of prescription drugs for human use', ), 293 => array ( 'TICID' => 51260, 'Description' => 'Free samples of drugs for animal use', ), 294 => array ( 'TICID' => 51265, 'Description' => 'Free Samples', ), 295 => array ( 'TICID' => 51270, 'Description' => 'Free samples of prescription drugs for animal use', ), 296 => array ( 'TICID' => 52000, 'Description' => 'Durable medical equipment', ), 297 => array ( 'TICID' => 52005, 'Description' => 'for Commercial/Industrial/Civic use', ), 298 => array ( 'TICID' => 52010, 'Description' => 'Durable medical equipment without a prescription', ), 299 => array ( 'TICID' => 52020, 'Description' => 'Durable medical equipment with a prescription', ), 300 => array ( 'TICID' => 52030, 'Description' => 'Durable medical equipment with a prescription paid for by Medicare', ), 301 => array ( 'TICID' => 52040, 'Description' => 'Durable medical equipment with a prescription reimbursed by Medicare', ), 302 => array ( 'TICID' => 52050, 'Description' => 'Durable medical equipment with a prescription paid for by MedicaidAppendix E', ), 303 => array ( 'TICID' => 52060, 'Description' => 'Durable medical equipment with a prescription reimbursed by Medicaid', ), 304 => array ( 'TICID' => 52065, 'Description' => 'for home use', ), 305 => array ( 'TICID' => 52070, 'Description' => 'Durable medical equipment for home use without a prescription', ), 306 => array ( 'TICID' => 52080, 'Description' => 'Durable medical equipment for home use with a prescription', ), 307 => array ( 'TICID' => 52090, 'Description' => 'Durable medical equipment for home use with a prescription paid for by Medicare', ), 308 => array ( 'TICID' => 52100, 'Description' => 'Durable medical equipment for home use with a prescription reimbursed by Medicare', ), 309 => array ( 'TICID' => 52110, 'Description' => 'Durable medical equipment for home use with a prescription paid for by Medicaid', ), 310 => array ( 'TICID' => 52120, 'Description' => 'Durable medical equipment for home use with a prescription reimbursed by Medicaid', ), 311 => array ( 'TICID' => 52125, 'Description' => 'Oxygen delivery equipment', ), 312 => array ( 'TICID' => 52128, 'Description' => 'for Commercial/Industrial/Civic use', ), 313 => array ( 'TICID' => 52130, 'Description' => 'Oxygen delivery equipment without a prescription', ), 314 => array ( 'TICID' => 52140, 'Description' => 'Oxygen delivery equipment with a prescription', ), 315 => array ( 'TICID' => 52150, 'Description' => 'Oxygen delivery equipment with a prescription paid for by Medicare', ), 316 => array ( 'TICID' => 52160, 'Description' => 'Oxygen delivery equipment with a prescription reimbursed by Medicare', ), 317 => array ( 'TICID' => 52170, 'Description' => 'Oxygen delivery equipment with a prescription paid for by Medicaid', ), 318 => array ( 'TICID' => 52180, 'Description' => 'Oxygen delivery equipment with a prescription reimbursed by Medicaid', ), 319 => array ( 'TICID' => 52185, 'Description' => 'for home use', ), 320 => array ( 'TICID' => 52190, 'Description' => 'Oxygen delivery equipment for home use without a prescription', ), 321 => array ( 'TICID' => 52200, 'Description' => 'Oxygen delivery equipment for home use with a prescription', ), 322 => array ( 'TICID' => 52210, 'Description' => 'Oxygen delivery equipment with a prescription for home use paid for by Medicare', ), 323 => array ( 'TICID' => 52220, 'Description' => 'Oxygen delivery equipment with a prescription for home use reimbursed by Medicare', ), 324 => array ( 'TICID' => 52230, 'Description' => 'Oxygen delivery equipment with a prescription for home use paid for by Medicaid', ), 325 => array ( 'TICID' => 52240, 'Description' => 'Oxygen delivery equipment with a prescription for home use reimbursed by Medicaid', ), 326 => array ( 'TICID' => 52245, 'Description' => 'Kidney dialysis equipment', ), 327 => array ( 'TICID' => 52248, 'Description' => 'for Commercial/Industrial/Civic use', ), 328 => array ( 'TICID' => 52250, 'Description' => 'Kidney dialysis equipment without a prescription', ), 329 => array ( 'TICID' => 52260, 'Description' => 'Kidney dialysis equipment with a prescription', ), 330 => array ( 'TICID' => 52270, 'Description' => 'Kidney dialysis equipment with a prescription paid for by Medicare', ), 331 => array ( 'TICID' => 52280, 'Description' => 'Kidney dialysis equipment with a prescription reimbursed by Medicare', ), 332 => array ( 'TICID' => 52290, 'Description' => 'Kidney dialysis equipment with a prescription paid for by Medicaid', ), 333 => array ( 'TICID' => 52300, 'Description' => 'Kidney dialysis equipment with a prescription reimbursed by Medicaid', ), 334 => array ( 'TICID' => 52305, 'Description' => 'for home use', ), 335 => array ( 'TICID' => 52310, 'Description' => 'Kidney dialysis equipment for home use without a prescription', ), 336 => array ( 'TICID' => 52320, 'Description' => 'Kidney dialysis equipment for home use with a prescription', ), 337 => array ( 'TICID' => 52330, 'Description' => 'Kidney dialysis equipment for home use with a prescription paid for by Medicare', ), 338 => array ( 'TICID' => 52340, 'Description' => 'Kidney dialysis equipment for home use with a prescription reimbursed by Medicare', ), 339 => array ( 'TICID' => 52350, 'Description' => 'Kidney dialysis equipment for home use with a prescription paid for by Medicaid', ), 340 => array ( 'TICID' => 52360, 'Description' => 'Kidney dialysis equipment for home use with a prescription reimbursed by Medicaid', ), 341 => array ( 'TICID' => 52365, 'Description' => 'Enteral feeding systems', ), 342 => array ( 'TICID' => 52368, 'Description' => 'for Commercial/Industrial/Civic use', ), 343 => array ( 'TICID' => 52370, 'Description' => 'Enteral feeding systems without a prescription', ), 344 => array ( 'TICID' => 52380, 'Description' => 'Enteral feeding systems with a prescription', ), 345 => array ( 'TICID' => 52390, 'Description' => 'Enteral feeding systems with a prescription paid for by Medicare', ), 346 => array ( 'TICID' => 52400, 'Description' => 'Enteral feeding systems with a prescription reimbursed by Medicare', ), 347 => array ( 'TICID' => 52410, 'Description' => 'Enteral feeding systems with a prescription paid for by Medicaid', ), 348 => array ( 'TICID' => 52420, 'Description' => 'Enteral feeding systems with a prescription reimbursed by Medicaid', ), 349 => array ( 'TICID' => 52425, 'Description' => 'for home use', ), 350 => array ( 'TICID' => 52430, 'Description' => 'Enteral feeding systems for home use without a prescription', ), 351 => array ( 'TICID' => 52440, 'Description' => 'Enteral feeding systems for home use with a prescription ', ), 352 => array ( 'TICID' => 52450, 'Description' => 'Enteral feeding systems for home use with a prescription paid for by Medicare', ), 353 => array ( 'TICID' => 52460, 'Description' => 'Enteral feeding systems for home use with a prescription reimbursed by Medicare', ), 354 => array ( 'TICID' => 52470, 'Description' => 'Enteral feeding systems for home use with a prescription paid for by Medicaid', ), 355 => array ( 'TICID' => 52480, 'Description' => 'Enteral feeding systems for home use with a prescription reimbursed by Medicaid', ), 356 => array ( 'TICID' => 52490, 'Description' => 'Repair and replacement parts for durable medical equipment which are for single patient use', ), 357 => array ( 'TICID' => 52500, 'Description' => 'Breast pump, not for home use, without a prescription', ), 358 => array ( 'TICID' => 52501, 'Description' => 'Breast pump, not for home use, with a prescription', ), 359 => array ( 'TICID' => 52502, 'Description' => 'Breast pump, not for home use, with a prescription paid by Medicare', ), 360 => array ( 'TICID' => 52503, 'Description' => 'Breast pump, not for home use, with a prescription reimbursed by Medicare', ), 361 => array ( 'TICID' => 52504, 'Description' => 'Breast pump, not for home use, with a prescription paid by Medicaid', ), 362 => array ( 'TICID' => 52505, 'Description' => 'Breast pump, not for home use, with a prescription reimbursed by Medicaid', ), 363 => array ( 'TICID' => 52506, 'Description' => 'Breast pump, for home use, without a prescription', ), 364 => array ( 'TICID' => 52507, 'Description' => 'Breast pump, for home use, with a prescription', ), 365 => array ( 'TICID' => 52508, 'Description' => 'Breast pump, for home use, with a prescription paid for by Medicare', ), 366 => array ( 'TICID' => 52509, 'Description' => 'Breast pump, for home use, with a prescription reimbursed by Medicare', ), 367 => array ( 'TICID' => 52510, 'Description' => 'Breast pump, for home use, with a prescription paid for by Medicaid', ), 368 => array ( 'TICID' => 52511, 'Description' => 'Breast pump, for home use, with a prescription reimbursed by Medicaid', ), 369 => array ( 'TICID' => 52512, 'Description' => 'Repair and replacement parts for breast pump which are for single patient use', ), 370 => array ( 'TICID' => 52515, 'Description' => 'Breast pump collection and storage supplies, not for home use, without a prescription', ), 371 => array ( 'TICID' => 52516, 'Description' => 'Breast pump collection and storage supplies, not for home use, with a prescription', ), 372 => array ( 'TICID' => 52517, 'Description' => 'Breast pump collection and storage supplies, not for home use, with a prescription, paid by Medicare', ), 373 => array ( 'TICID' => 52518, 'Description' => 'Breast pump collection and storage supplies, not for home use, with a prescription, reimbursed by Medicare', ), 374 => array ( 'TICID' => 52519, 'Description' => 'Breast pump collection and storage supplies, not for home use, with a prescription, paid for by Medicaid', ), 375 => array ( 'TICID' => 52520, 'Description' => 'Breast pump collection and storage supplies, not for home use, with a prescription, reimbursed by Medicaid', ), 376 => array ( 'TICID' => 52521, 'Description' => 'Breast pump collection and storage supplies, for home use, without a prescription', ), 377 => array ( 'TICID' => 52522, 'Description' => 'Breast pump collection and storage supplies, for home use, with a prescription', ), 378 => array ( 'TICID' => 52523, 'Description' => 'Breast pump collection and storage supplies, for home use, with a prescription, paid for by Medicare', ), 379 => array ( 'TICID' => 52524, 'Description' => 'Breast pump collection and storage supplies, for home use, with a prescription, reimbursed by Medicare', ), 380 => array ( 'TICID' => 52525, 'Description' => 'Breast pump collection and storage supplies, for home use, with a prescription, paid for by Medicaid', ), 381 => array ( 'TICID' => 52526, 'Description' => 'Breast pump collection and storage supplies, for home use, with a prescription, reimbursed by Medicaid', ), 382 => array ( 'TICID' => 52530, 'Description' => 'Breast pump kit, not for home use, without a prescription', ), 383 => array ( 'TICID' => 52531, 'Description' => 'Breast pump kit, not for home use, with a prescription', ), 384 => array ( 'TICID' => 52532, 'Description' => 'Breast pump kit, not for home use, with a prescription paid for by Medicare', ), 385 => array ( 'TICID' => 52534, 'Description' => 'Breast pump kit, not for home use, with a prescription reimbursed by Medicare', ), 386 => array ( 'TICID' => 52535, 'Description' => 'Breast pump kit, not for home use, with a prescription paid for by Medicaid', ), 387 => array ( 'TICID' => 52536, 'Description' => 'Breast pump kit, not for home use, with a prescription reimbursed by Medicaid', ), 388 => array ( 'TICID' => 52537, 'Description' => 'Breast pump kit, for home use, without a prescription', ), 389 => array ( 'TICID' => 52538, 'Description' => 'Breast pump kit, for home use, with a prescription', ), 390 => array ( 'TICID' => 52539, 'Description' => 'Breast pump kit, for home use, with a prescription paid for by Medicare', ), 391 => array ( 'TICID' => 52540, 'Description' => 'Breast pump kit, for home use, with a prescription reimbursed by Medicare', ), 392 => array ( 'TICID' => 52541, 'Description' => 'Breast pump kit, for home use, with a prescription paid for by Medicaid', ), 393 => array ( 'TICID' => 52542, 'Description' => 'Breast pump kit, for home use, with a prescription reimbursed by Medicaid', ), 394 => array ( 'TICID' => 52543, 'Description' => 'Repair and replacement parts for breast pump kit which are for single patient use', ), 395 => array ( 'TICID' => 53000, 'Description' => 'Mobility enhancing equipment', ), 396 => array ( 'TICID' => 53010, 'Description' => 'Mobility enhancing equipment without a prescription', ), 397 => array ( 'TICID' => 53020, 'Description' => 'Mobility enhancing equipment with a prescriptionAppendix E', ), 398 => array ( 'TICID' => 53030, 'Description' => 'Mobility enhancing equipment with a prescription paid for by Medicare', ), 399 => array ( 'TICID' => 53040, 'Description' => 'Mobility enhancing equipment with a prescription reimbursed by Medicare', ), 400 => array ( 'TICID' => 53050, 'Description' => 'Mobility enhancing equipment with a prescription paid for by Medicaid', ), 401 => array ( 'TICID' => 53060, 'Description' => 'Mobility enhancing equipment with a prescription reimbursed by Medicaid', ), 402 => array ( 'TICID' => 54000, 'Description' => 'Prosthetic devices', ), 403 => array ( 'TICID' => 54010, 'Description' => 'Prosthetic devices without a prescription', ), 404 => array ( 'TICID' => 54020, 'Description' => 'Prosthetic devices with a prescription', ), 405 => array ( 'TICID' => 54030, 'Description' => 'Prosthetic devices paid with a prescription for by Medicare', ), 406 => array ( 'TICID' => 54040, 'Description' => 'Prosthetic devices with a prescription reimbursed by Medicare', ), 407 => array ( 'TICID' => 54050, 'Description' => 'Prosthetic devices with a prescription paid for by Medicaid', ), 408 => array ( 'TICID' => 54060, 'Description' => 'Prosthetic devices with a prescription reimbursed by Medicaid', ), 409 => array ( 'TICID' => 54065, 'Description' => 'Corrective eyeglasses', ), 410 => array ( 'TICID' => 54070, 'Description' => 'Corrective eyeglasses without a prescription', ), 411 => array ( 'TICID' => 54080, 'Description' => 'Corrective eyeglasses with a prescription', ), 412 => array ( 'TICID' => 54090, 'Description' => 'Corrective eyeglasses with a prescription paid for by Medicare', ), 413 => array ( 'TICID' => 54100, 'Description' => 'Corrective eyeglasses with a prescription reimbursed by Medicare', ), 414 => array ( 'TICID' => 54110, 'Description' => 'Corrective eyeglasses with a prescription paid for by Medicaid', ), 415 => array ( 'TICID' => 54120, 'Description' => 'Corrective eyeglasses with a prescription reimbursed by Medicaid', ), 416 => array ( 'TICID' => 54125, 'Description' => 'Contact lenses', ), 417 => array ( 'TICID' => 54130, 'Description' => 'Contact lenses without a prescription', ), 418 => array ( 'TICID' => 54140, 'Description' => 'Contact lenses with a prescription', ), 419 => array ( 'TICID' => 54150, 'Description' => 'Contact lenses with a prescription paid for by Medicare', ), 420 => array ( 'TICID' => 54160, 'Description' => 'Contact lenses with a prescription reimbursed by Medicare', ), 421 => array ( 'TICID' => 54170, 'Description' => 'Contact lenses with a prescription paid for by Medicaid', ), 422 => array ( 'TICID' => 54180, 'Description' => 'Contact lenses with a prescription reimbursed by Medicaid', ), 423 => array ( 'TICID' => 54185, 'Description' => 'Hearing aids', ), 424 => array ( 'TICID' => 54190, 'Description' => 'Hearing aids without a prescription', ), 425 => array ( 'TICID' => 54200, 'Description' => 'Hearing aids with a prescription', ), 426 => array ( 'TICID' => 54210, 'Description' => 'Hearing aids with a prescription paid for by Medicare', ), 427 => array ( 'TICID' => 54220, 'Description' => 'Hearing aids with a prescription reimbursed by Medicare', ), 428 => array ( 'TICID' => 54230, 'Description' => 'Hearing aids with a prescription paid for by Medicaid', ), 429 => array ( 'TICID' => 54240, 'Description' => 'Hearing aids with a prescription reimbursed by Medicaid', ), 430 => array ( 'TICID' => 54245, 'Description' => 'Dental prosthesis', ), 431 => array ( 'TICID' => 54250, 'Description' => 'Dental prosthesis without a prescription', ), 432 => array ( 'TICID' => 54260, 'Description' => 'Dental prosthesis with a prescription', ), 433 => array ( 'TICID' => 54270, 'Description' => 'Dental prosthesis with a prescription paid for by Medicare', ), 434 => array ( 'TICID' => 54280, 'Description' => 'Dental prosthesis with a prescription reimbursed by Medicare', ), 435 => array ( 'TICID' => 54290, 'Description' => 'Dental prosthesis with a prescription paid for by Medicaid', ), 436 => array ( 'TICID' => 54300, 'Description' => 'Dental prosthesis with a prescription reimbursed by Medicaid', ), 437 => array ( 'TICID' => 60000, 'Description' => 'Telecommunications service', ), 438 => array ( 'TICID' => 60010, 'Description' => 'Ancillary Services', ), 439 => array ( 'TICID' => 60020, 'Description' => 'Conference bridging service', ), 440 => array ( 'TICID' => 60030, 'Description' => 'Detailed telecommunications billing service', ), 441 => array ( 'TICID' => 60040, 'Description' => 'Directory assistance', ), 442 => array ( 'TICID' => 60050, 'Description' => 'Vertical service', ), 443 => array ( 'TICID' => 60060, 'Description' => 'Voice mail service', ), 444 => array ( 'TICID' => 61000, 'Description' => 'Intrastate Telecommunications Service', ), 445 => array ( 'TICID' => 61010, 'Description' => 'Interstate Telecommunications ServiceAppendix E', ), 446 => array ( 'TICID' => 61020, 'Description' => 'International Telecommunications Service', ), 447 => array ( 'TICID' => 61030, 'Description' => 'International 800 service', ), 448 => array ( 'TICID' => 61040, 'Description' => 'International 900 service', ), 449 => array ( 'TICID' => 61050, 'Description' => 'International fixed wireless service', ), 450 => array ( 'TICID' => 61060, 'Description' => 'International mobile wireless service', ), 451 => array ( 'TICID' => 61070, 'Description' => 'International paging service', ), 452 => array ( 'TICID' => 61080, 'Description' => 'International prepaid calling service', ), 453 => array ( 'TICID' => 61090, 'Description' => 'International prepaid wireless calling service', ), 454 => array ( 'TICID' => 61100, 'Description' => 'International private communications service', ), 455 => array ( 'TICID' => 61110, 'Description' => 'International value-added non-voice data service', ), 456 => array ( 'TICID' => 61120, 'Description' => 'International residential telecommunications service', ), 457 => array ( 'TICID' => 61130, 'Description' => 'Interstate 800 service', ), 458 => array ( 'TICID' => 61140, 'Description' => 'Interstate 900 service', ), 459 => array ( 'TICID' => 61150, 'Description' => 'Interstate fixed wireless service', ), 460 => array ( 'TICID' => 61160, 'Description' => 'Interstate mobile wireless service', ), 461 => array ( 'TICID' => 61170, 'Description' => 'Interstate paging service', ), 462 => array ( 'TICID' => 61180, 'Description' => 'Interstate prepaid calling service', ), 463 => array ( 'TICID' => 61190, 'Description' => 'Interstate prepaid wireless calling service', ), 464 => array ( 'TICID' => 61200, 'Description' => 'Interstate private communications service', ), 465 => array ( 'TICID' => 61210, 'Description' => 'Interstate value-added non-voice data service', ), 466 => array ( 'TICID' => 61220, 'Description' => 'Interstate residential telecommunications service', ), 467 => array ( 'TICID' => 61230, 'Description' => 'Intrastate 800 service', ), 468 => array ( 'TICID' => 61240, 'Description' => 'Intrastate 900 service', ), 469 => array ( 'TICID' => 61250, 'Description' => 'Intrastate fixed wireless service', ), 470 => array ( 'TICID' => 61260, 'Description' => 'Intrastate mobile wireless service', ), 471 => array ( 'TICID' => 61270, 'Description' => 'Intrastate paging service', ), 472 => array ( 'TICID' => 61280, 'Description' => 'Intrastate prepaid calling service', ), 473 => array ( 'TICID' => 61290, 'Description' => 'Intrastate prepaid wireless calling service', ), 474 => array ( 'TICID' => 61300, 'Description' => 'Intrastate private communications service', ), 475 => array ( 'TICID' => 61310, 'Description' => 'Intrastate value-added non-voice data service', ), 476 => array ( 'TICID' => 61320, 'Description' => 'Intrastate residential telecommunications service', ), 477 => array ( 'TICID' => 61325, 'Description' => 'Paging service', ), 478 => array ( 'TICID' => 61330, 'Description' => 'Coin-operated telephone service', ), 479 => array ( 'TICID' => 61340, 'Description' => 'Pay telephone service', ), 480 => array ( 'TICID' => 61350, 'Description' => 'Local Service as defined by state', ), 481 => array ( 'TICID' => 70010, 'Description' => 'Firearm safety device', ), 482 => array ( 'TICID' => 70011, 'Description' => 'Firearm storage device except items 70012, 70013 and 70014', ), 483 => array ( 'TICID' => 70012, 'Description' => 'Glass-faced cabinets that are designed to display the firearm.', ), 484 => array ( 'TICID' => 70013, 'Description' => 'Containers or other forms of storage that are designed to display the firearm', ), 485 => array ( 'TICID' => 70014, 'Description' => 'Any other enclosure that is marketed to store a firearm.', ), 486 => array ( 'TICID' => 90010, 'Description' => 'Meal Replacement', ), 487 => array ( 'TICID' => 90011, 'Description' => 'Vitamins', ), 488 => array ( 'TICID' => 90012, 'Description' => 'Unprepared Food', ), 489 => array ( 'TICID' => 90020, 'Description' => 'F.O.B. Origin Shipping', ), 490 => array ( 'TICID' => 90021, 'Description' => 'F.O.B. Destination Shipping', ), 491 => array ( 'TICID' => 90022, 'Description' => 'Shipping (optional customer pickup)', ), 492 => array ( 'TICID' => 90041, 'Description' => 'Installation Services (deprecated)', ), 493 => array ( 'TICID' => 90100, 'Description' => 'Flags and Banners', ), 494 => array ( 'TICID' => 90101, 'Description' => 'State and province flags', ), 495 => array ( 'TICID' => 90102, 'Description' => 'Special causes flags', ), 496 => array ( 'TICID' => 90103, 'Description' => 'United States flag', ), 497 => array ( 'TICID' => 90104, 'Description' => 'Connecticut flag', ), 498 => array ( 'TICID' => 90105, 'Description' => 'Florida flag', ), 499 => array ( 'TICID' => 90106, 'Description' => 'Maryland flag', ), 500 => array ( 'TICID' => 90107, 'Description' => 'Massachusetts flag', ), 501 => array ( 'TICID' => 90108, 'Description' => 'New Jersey flag', ), 502 => array ( 'TICID' => 90109, 'Description' => 'New York flag', ), 503 => array ( 'TICID' => 90110, 'Description' => 'Pennsylvania flag', ), 504 => array ( 'TICID' => 90111, 'Description' => 'Rhode Island flag', ), 505 => array ( 'TICID' => 90112, 'Description' => 'Tennessee flag', ), 506 => array ( 'TICID' => 90113, 'Description' => 'Vermont flag', ), 507 => array ( 'TICID' => 90114, 'Description' => 'Wisconsin flag', ), 508 => array ( 'TICID' => 90115, 'Description' => 'Virginia flag', ), 509 => array ( 'TICID' => 90116, 'Description' => 'West Virginia flag', ), 510 => array ( 'TICID' => 90117, 'Description' => 'POW-MIA', ), 511 => array ( 'TICID' => 90118, 'Description' => 'Novelty and Organizational Flags', ), 512 => array ( 'TICID' => 90119, 'Description' => 'National flags', ), 513 => array ( 'TICID' => 90200, 'Description' => 'Medical Records', ), 514 => array ( 'TICID' => 90300, 'Description' => 'Alcoholic Beverages', ), 515 => array ( 'TICID' => 90400, 'Description' => 'Beverages', ), 516 => array ( 'TICID' => 90401, 'Description' => 'Water', ), 517 => array ( 'TICID' => 90403, 'Description' => 'Emergency Water', ), 518 => array ( 'TICID' => 90404, 'Description' => 'Flavored Water', ), 519 => array ( 'TICID' => 90405, 'Description' => 'Milk', ), 520 => array ( 'TICID' => 90406, 'Description' => 'Juice', ), 521 => array ( 'TICID' => 90407, 'Description' => 'Fruit Drink', ), 522 => array ( 'TICID' => 90408, 'Description' => 'Coffee', ), 523 => array ( 'TICID' => 90409, 'Description' => 'Tea', ), 524 => array ( 'TICID' => 90410, 'Description' => 'Breath Mints', ), 525 => array ( 'TICID' => 90411, 'Description' => 'Energy Shots', ), 526 => array ( 'TICID' => 90412, 'Description' => 'CBD (cannabidiol) Products (Ingestible)', ), 527 => array ( 'TICID' => 90413, 'Description' => 'CBD (cannabidiol) Products (Noningestible)', ), 528 => array ( 'TICID' => 90414, 'Description' => 'Electrolyte or rehydration beverage (DOES NOT contain milk or milk products ingredients)', ), 529 => array ( 'TICID' => 90415, 'Description' => 'Electrolyte or rehydration beverage (contains milk or milk product ingredients)', ), 530 => array ( 'TICID' => 90416, 'Description' => 'Electrolyte or rehydration powder (DOES NOT contain milk product ingredients)', ), 531 => array ( 'TICID' => 90417, 'Description' => 'Electrolyte or rehydration powder (contains milk product ingredients)', ), 532 => array ( 'TICID' => 90500, 'Description' => 'Firearms and Hunting', ), 533 => array ( 'TICID' => 90501, 'Description' => 'Excercise Clothing', ), 534 => array ( 'TICID' => 90502, 'Description' => 'Gun Safe', ), 535 => array ( 'TICID' => 90503, 'Description' => 'Gun Safety Devices Permanent', ), 536 => array ( 'TICID' => 90504, 'Description' => 'Gun Safety Devices Temporary', ), 537 => array ( 'TICID' => 90505, 'Description' => 'Firearms', ), 538 => array ( 'TICID' => 90506, 'Description' => 'Ammunition', ), 539 => array ( 'TICID' => 90600, 'Description' => 'Fuel', ), 540 => array ( 'TICID' => 90601, 'Description' => 'Dyed diesel fuel (off-road use)', ), 541 => array ( 'TICID' => 90700, 'Description' => 'Agricultural and Farming', ), 542 => array ( 'TICID' => 90701, 'Description' => 'Honey bees and their input and byproducts', ), 543 => array ( 'TICID' => 91000, 'Description' => 'Services', ), 544 => array ( 'TICID' => 91001, 'Description' => 'Credit Reports', ), 545 => array ( 'TICID' => 91010, 'Description' => 'Help Desk Support', ), 546 => array ( 'TICID' => 91011, 'Description' => 'Computer Repair', ), 547 => array ( 'TICID' => 91020, 'Description' => 'Voluntary Gratuity', ), 548 => array ( 'TICID' => 91021, 'Description' => 'Mandatory Gratuity (charge does not exceed 20% of sales price)', ), 549 => array ( 'TICID' => 91022, 'Description' => 'Mandatory Gratuity (charge exceeds 20% of sales price)', ), 550 => array ( 'TICID' => 91030, 'Description' => 'Donations', ), 551 => array ( 'TICID' => 91040, 'Description' => 'Graphic Design Service', ), 552 => array ( 'TICID' => 91041, 'Description' => 'Graphic Design Review Service', ), 553 => array ( 'TICID' => 91050, 'Description' => 'Alarm Monitoring', ), 554 => array ( 'TICID' => 91051, 'Description' => 'Alarm Repair Service', ), 555 => array ( 'TICID' => 91060, 'Description' => 'Merchant Operated', ), 556 => array ( 'TICID' => 91061, 'Description' => 'Equipment Rentals', ), 557 => array ( 'TICID' => 91062, 'Description' => 'Customer Operated', ), 558 => array ( 'TICID' => 91063, 'Description' => 'Rental Parts or Supplies', ), 559 => array ( 'TICID' => 91064, 'Description' => 'Sports or Amusement', ), 560 => array ( 'TICID' => 91065, 'Description' => 'For Tax Exempt Project', ), 561 => array ( 'TICID' => 91070, 'Description' => 'Membership fees', ), 562 => array ( 'TICID' => 91071, 'Description' => 'Youth Club', ), 563 => array ( 'TICID' => 91072, 'Description' => 'Health Club', ), 564 => array ( 'TICID' => 91073, 'Description' => 'Social or Civic Organization', ), 565 => array ( 'TICID' => 91074, 'Description' => 'Retail Club', ), 566 => array ( 'TICID' => 91080, 'Description' => 'Admission fees', ), 567 => array ( 'TICID' => 91081, 'Description' => 'Spectator Admission fees', ), 568 => array ( 'TICID' => 91082, 'Description' => 'Participant Admission fees', ), 569 => array ( 'TICID' => 91083, 'Description' => 'Cultural Admission fees', ), 570 => array ( 'TICID' => 91090, 'Description' => 'Service Labor for Repairs of Tangible Personal Property', ), 571 => array ( 'TICID' => 91100, 'Description' => 'Waste collection and removal services', ), 572 => array ( 'TICID' => 91110, 'Description' => 'Gift wrapping services, optional', ), 573 => array ( 'TICID' => 91120, 'Description' => 'Medical Services', ), 574 => array ( 'TICID' => 91121, 'Description' => 'Lab Testing Services', ), 575 => array ( 'TICID' => 91200, 'Description' => 'Pet Services', ), 576 => array ( 'TICID' => 91201, 'Description' => 'Pet Sitting Services', ), 577 => array ( 'TICID' => 92001, 'Description' => 'Handbags', ), 578 => array ( 'TICID' => 92002, 'Description' => 'Handkerchiefs', ), 579 => array ( 'TICID' => 92003, 'Description' => 'Belt buckles', ), 580 => array ( 'TICID' => 92004, 'Description' => 'Clothing components', ), 581 => array ( 'TICID' => 92005, 'Description' => 'Athletic shoes', ), 582 => array ( 'TICID' => 92006, 'Description' => 'Athletic supporters', ), 583 => array ( 'TICID' => 92007, 'Description' => 'Bicycle helmet', ), 584 => array ( 'TICID' => 92008, 'Description' => 'Snow Suits', ), 585 => array ( 'TICID' => 92009, 'Description' => 'Imitation fur clothing', ), 586 => array ( 'TICID' => 92010, 'Description' => 'Specialty clothing', ), 587 => array ( 'TICID' => 92011, 'Description' => 'Formal wear', ), 588 => array ( 'TICID' => 92012, 'Description' => 'Swim suits', ), 589 => array ( 'TICID' => 92013, 'Description' => 'Scarves', ), 590 => array ( 'TICID' => 92014, 'Description' => 'Costumes', ), 591 => array ( 'TICID' => 92015, 'Description' => 'Athletic clothing', ), 592 => array ( 'TICID' => 92016, 'Description' => 'Clothing Rental', ), 593 => array ( 'TICID' => 92017, 'Description' => 'Religious Materials and Texts', ), 594 => array ( 'TICID' => 92018, 'Description' => 'Religious Materials and Texts', ), 595 => array ( 'TICID' => 92019, 'Description' => 'Feminine Hygiene Products', ), 596 => array ( 'TICID' => 92020, 'Description' => 'Other Helmets', ), 597 => array ( 'TICID' => 92021, 'Description' => 'Hunting Clothing', ), 598 => array ( 'TICID' => 92022, 'Description' => 'Sewing Materials', ), 599 => array ( 'TICID' => 92023, 'Description' => 'Infant Supplies', ), 600 => array ( 'TICID' => 92024, 'Description' => 'Disposable Medical Supplies', ), 601 => array ( 'TICID' => 92025, 'Description' => 'Disability-Related Supplies', ), 602 => array ( 'TICID' => 92026, 'Description' => 'Disposable Veterinary Supplies', ), 603 => array ( 'TICID' => 92030, 'Description' => 'Religious Products', ), 604 => array ( 'TICID' => 92031, 'Description' => 'Religious Materials and Texts', ), 605 => array ( 'TICID' => 92032, 'Description' => 'Altar paraphernalia, sacramental chalices, and similar church supplies and equipment', ), 606 => array ( 'TICID' => 92050, 'Description' => 'Infant and Child Products and Supplies', ), 607 => array ( 'TICID' => 92051, 'Description' => 'Infant Supplies', ), 608 => array ( 'TICID' => 92052, 'Description' => 'Baby cribs', ), 609 => array ( 'TICID' => 92053, 'Description' => 'Baby playpens', ), 610 => array ( 'TICID' => 92054, 'Description' => 'Baby strollers', ), 611 => array ( 'TICID' => 92055, 'Description' => 'Child restraint devices and booster seats', ), 612 => array ( 'TICID' => 92056, 'Description' => 'Child bicycle carriers', ), 613 => array ( 'TICID' => 92057, 'Description' => 'Baby safety gates, monitors, cabinet locks and latches, socket covers', ), 614 => array ( 'TICID' => 92058, 'Description' => 'Baby exercisers, jumpers, bouncer seats, and swings', ), 615 => array ( 'TICID' => 92059, 'Description' => 'Baby changing tables and changing pads', ), 616 => array ( 'TICID' => 92060, 'Description' => 'Baby Wipes', ), 617 => array ( 'TICID' => 92061, 'Description' => 'Diaper Cream', ), 618 => array ( 'TICID' => 92062, 'Description' => 'Baby and toddler clothing', ), 619 => array ( 'TICID' => 92090, 'Description' => 'College Textbooks', ), 620 => array ( 'TICID' => 92095, 'Description' => 'Book - Children ', ), 621 => array ( 'TICID' => 92100, 'Description' => 'Jewelry', ), 622 => array ( 'TICID' => 92500, 'Description' => 'Coins and Commemoratives', ), 623 => array ( 'TICID' => 92501, 'Description' => 'Cremation Urns', ), 624 => array ( 'TICID' => 92502, 'Description' => 'Caskets and Vaults', ), 625 => array ( 'TICID' => 92503, 'Description' => 'Paper Money', ), 626 => array ( 'TICID' => 92504, 'Description' => 'Collectible Paper Money', ), 627 => array ( 'TICID' => 92505, 'Description' => 'Collectible Stamps', ), 628 => array ( 'TICID' => 92506, 'Description' => 'Bullion', ), 629 => array ( 'TICID' => 92507, 'Description' => 'Coins', ), 630 => array ( 'TICID' => 92508, 'Description' => 'Coins that are legal tender of United States.', ), 631 => array ( 'TICID' => 93011, 'Description' => 'Computer Peripheral', ), 632 => array ( 'TICID' => 93012, 'Description' => 'Gaming Peripherals', ), 633 => array ( 'TICID' => 93013, 'Description' => 'Personal Digital Assistants (PDAs)', ), 634 => array ( 'TICID' => 93015, 'Description' => 'Printers', ), 635 => array ( 'TICID' => 93016, 'Description' => 'Printer Supplies', ), 636 => array ( 'TICID' => 93017, 'Description' => 'Business Supplies and Equipment', ), 637 => array ( 'TICID' => 93018, 'Description' => 'Optional Equipment Protection Plan', ), 638 => array ( 'TICID' => 93101, 'Description' => 'Other/Miscellaneous', ), 639 => array ( 'TICID' => 93102, 'Description' => 'Digital Games', ), 640 => array ( 'TICID' => 93103, 'Description' => 'Downloadable Games', ), 641 => array ( 'TICID' => 93104, 'Description' => 'Online Games', ), 642 => array ( 'TICID' => 93105, 'Description' => 'Exempt Entity Works', ), 643 => array ( 'TICID' => 93110, 'Description' => 'News and Information', ), 644 => array ( 'TICID' => 93111, 'Description' => 'Newspapers', ), 645 => array ( 'TICID' => 93112, 'Description' => 'Single Issue', ), 646 => array ( 'TICID' => 93113, 'Description' => 'Subscription', ), 647 => array ( 'TICID' => 93115, 'Description' => 'Periodicals', ), 648 => array ( 'TICID' => 93116, 'Description' => 'Single Issue', ), 649 => array ( 'TICID' => 93117, 'Description' => 'Subscription', ), 650 => array ( 'TICID' => 93119, 'Description' => 'Web Site (subscriptions-based)', ), 651 => array ( 'TICID' => 93200, 'Description' => 'Remote Data Processing Software', ), 652 => array ( 'TICID' => 93201, 'Description' => 'Remote Data Processing Service', ), 653 => array ( 'TICID' => 93202, 'Description' => 'Infrastructure as a Service', ), 654 => array ( 'TICID' => 93203, 'Description' => 'Remote Storage Service', ), 655 => array ( 'TICID' => 93204, 'Description' => 'Remote CPU Service', ), 656 => array ( 'TICID' => 93205, 'Description' => 'Remotely Accessed Software for Business use', ), 657 => array ( 'TICID' => 93206, 'Description' => 'Remotely Accessed Software for personal use', ), 658 => array ( 'TICID' => 94000, 'Description' => 'Construction Materials', ), 659 => array ( 'TICID' => 94001, 'Description' => 'General Materials', ), 660 => array ( 'TICID' => 94002, 'Description' => 'Lumber', ), 661 => array ( 'TICID' => 94003, 'Description' => 'Engineered Materials', ), 662 => array ( 'TICID' => 94030, 'Description' => 'Screen Printing Equipment and Supplies', ), 663 => array ( 'TICID' => 94031, 'Description' => 'INK', ), 664 => array ( 'TICID' => 94032, 'Description' => 'INK ADDITIVES', ), 665 => array ( 'TICID' => 94033, 'Description' => 'PELLONS', ), 666 => array ( 'TICID' => 94034, 'Description' => 'SCORCH OUT', ), 667 => array ( 'TICID' => 94035, 'Description' => 'SPOT REMOVER', ), 668 => array ( 'TICID' => 94036, 'Description' => 'TEST TUBES', ), 669 => array ( 'TICID' => 94037, 'Description' => 'ALL WIPES', ), 670 => array ( 'TICID' => 94038, 'Description' => 'Other Screen Printing Equipment and Supplies', ), 671 => array ( 'TICID' => 95101, 'Description' => 'Common Household Remedies', ), 672 => array ( 'TICID' => 96100, 'Description' => 'Common Household Supplies', ), 673 => array ( 'TICID' => 96101, 'Description' => 'Disposable Household Paper Products', ), 674 => array ( 'TICID' => 96102, 'Description' => 'Toilet Tissue', ), 675 => array ( 'TICID' => 96103, 'Description' => 'Laundry detergent and supplies: powder, liquid, or pod detergent; fabric softener; dryer sheets; stain removers; bleach', ), 676 => array ( 'TICID' => 96104, 'Description' => 'Dish soap and detergents, including powder, liquid, or pod detergents or rinse agents that can be used in dishwashers', ), 677 => array ( 'TICID' => 96105, 'Description' => 'Cleaning or disinfecting wipes and sprays, hand sanitizer', ), 678 => array ( 'TICID' => 96106, 'Description' => 'Trash bags', ), 679 => array ( 'TICID' => 96130, 'Description' => 'Shampoo (non-medicated)', ), 680 => array ( 'TICID' => 96131, 'Description' => 'Shampoo (medicated)', ), 681 => array ( 'TICID' => 96132, 'Description' => 'Toothpaste', ), 682 => array ( 'TICID' => 96133, 'Description' => 'Tootbrush', ), 683 => array ( 'TICID' => 96134, 'Description' => 'Mouthwash', ), 684 => array ( 'TICID' => 96135, 'Description' => 'Antiperspirants', ), 685 => array ( 'TICID' => 99991, 'Description' => 'Marketplace Sales', ), 686 => array ( 'TICID' => 99992, 'Description' => 'No Tax Calculation - File Returns Only', ), 687 => array ( 'TICID' => 99993, 'Description' => 'Marketplace Facilitator Fees', ), 688 => array ( 'TICID' => 99994, 'Description' => 'Core Charges', ), 689 => array ( 'TICID' => 99995, 'Description' => 'Tire Fees', ), 690 => array ( 'TICID' => 99996, 'Description' => 'CA eWaste Fees', ), 691 => array ( 'TICID' => 99997, 'Description' => 'Specialized', ), 692 => array ( 'TICID' => 99998, 'Description' => 'Use Tax Reporting', ), 693 => array ( 'TICID' => 99999, 'Description' => 'In-Store Sales', ), );
        foreach ($tmpArr as $tic) {
            $return[$tic['TICID']] = $tic['Description'];
        }
        return $return;

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
        $xApiKey = $payload->getXApiKey();
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
                $connectionId = $payload->getApiKey();
                $customerId = $payload->getCustomerID();
                $url = $this->base_url_without_connections_v3.'exemption-certificates?customerId='.$customerId."&&limit=100&&connectionId=".$connectionId;
                break;
            case "ping":
                $connectionId = $payload->getApiKey();
                $url = $this->base_url_v3."$connectionId/ping";
                break;
        }
        $curl = curl_init();
        $xApiKey = $payload->getXApiKey();;
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
                $xApiKey = $payload->getXApiKey();;
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
                $xApiKey = $payload->getXApiKey();
                $connectionId = $payload->getApiKey();
                $url = "{$this->base_url_v3}{$connectionId}/{$endpoint}";
                $params = [
                    "cartId" => $payload->getCartID(),
                    "completed" => $capture,
                    "orderId" =>  $payload->getOrderID()
                ];
                break;
            case "verify-address":
                $xApiKey = $payload->getXApiKey();;
                $url = "{$this->base_url_without_connections_v3}/{$endpoint}";
                $destinationLine1 = $payload->getAddress1();

                $zip = $payload->getZip5();
                if($payload->getZip4()){
                    $zip .= "-".$payload->getZip4();
                }
                $params = [
                  "city" => $payload->getCity(),
                  "countryCode" => "US",
                  "line1" => $destinationLine1,
                  "line2" => $payload->getAddress2(),
                  "state"=> $payload->getState(),
                  "zip" =>  $zip
                ];
                break;
            case "refunds":
                $connectionId = $payload->getApiKey();
                $xApiKey = $payload->getXApiKey();;
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
                $xApiKey = $payload->getXApiKey();;
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
