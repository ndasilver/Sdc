<?php
namespace Kamaro\Sdc;

use  Kamaro\Sdc\Devices\SerialPortManager;

/**
 * KPOS
 *
 * An Point of Sale application 
 *
 * @package   KPOS
 * @author    Kamaro Team
 * @copyright Copyright (c) 2011 - 2014, Kamaro, Inc.
 * @license   http://codeigniter.com/user_guide/license.html
 * @link    http://kamaropos.com
 * @since   Version 1.0
 * @filesource
 */

// ------------------------------------------------------------------------

/**
 * Common Functions
 *
 * Loads the base classes and executes the request.
 *
 * @package     KAMARO Point of Sale
 * @subpackage  KAMARO Point of Sale
 * @category    Common Functions fiscal Devices
 * @author      Kamaro Lambert (Thanks to Habamwabo Danny for the contribution)
 * @link        http://kamaropos.com/user_guide/
 */

// ------------------------------------------------------------------------

/**
* Determines if the SDC is connected then set it .
*
*
* @access public
* @param  string
* @return bool  TRUE if the current version is $version or higher
*/

Class SDCController { 
   /**
    * Contains Device
    * @var 
    */
   protected $device;

   function __construct(){

      error_reporting(E_ERROR);
      $this->device = new SerialPortManager();

     $this->device->open();
   }

   /**
    * Get SDC ID
    * @return string
    */
   public function getID(){
      // Hex for requesting signature
      // command = "01 24 20 E5 05 30 31 32 3E 03";
        $string_dig = $this->getSdcRequest("", "E5", "20");
       // Turn this into arry so that we can be able
       // to write byte by byte
       $string_array_dig=explode(' ',$string_dig);
             
       foreach ($string_array_dig as $string_hex_dig=>$value_dig)
       {
         $this->device->writeByte(" ".hexToByte($value_dig)."\r\n");
       }

     usleep(50);
     //Send request to the SDC asking the response 
     $string = $this->device->read();

     return substr($string, strpos($string,'SDC'), 12);  
   }

  /**
   * Get the Get SDC status
   * @return array
   */
  public function getStatus(){
       
       // Bytes for getting status
       // $string_dig= '01 24 20 E7 05 30 31 33 30 03';
       $string_dig = $this->getSdcRequest("", "E7", "24");

       $string_array_dig=explode(' ',$string_dig);
       
       foreach ($string_array_dig as $string_hex_dig=>$value_dig){
           $this->device->writeByte(" ".hexToByte($value_dig)."\r\n");
       }
      
      usleep(50);
      //Send request to the SDC asking the response 
      $str =  $this->device->read();
      
      $returned_data = implode(" ", strToHex($str));
      
      $returned_data = getStringBetween($returned_data,"E7","04");
      
      $hex_string    = str_replace(" ", "", $returned_data);

      $string        = explode(",", hexToStr($hex_string));

      $data['SDC serial number']                     = $string[0];
      $data['Firmware version']                      = $string[1];
      $data['Hardware revision']                     = $string[2];
      $data['The number of current SDC daily report']= $string[3];   
      $data['Last remote audit date and time']       = $string[4];
      $data['Last local audit date and time']        = $string[5];     
      return $data;   
  }


   /**
     * @author Lambert Kamaro
     * Number of bytes from <01> (excluded) to <05> (included) plus a fixed offset
     * 20h Length: 1 byte;
     * 
     * 
     */
    private function getLength($data){
      // Find the length by counting the data and adding length itsself, 
      // sequence,command,Post amble 05 (TOTAL=4)
      //, and 20h which is 32 in decimal which is 36 in total
      $length = hexdec(20) + 4; // 36

      if (!empty($data))
      {
          // Make sure that data is in capital letter 
          $data = strtoupper($data);
          $byte_array = unpack('C*', implode(' ', $data));
          $length += count($byte_array);
      }

      return dechex($length);
    }

 /**
    * @author Kamaro Lambert
    * Method to get the BCC or the HEX to send to SDC
    * ===============================================
    * Check sum (0000h-FFFFh)
    * Length: 4 bytes; value: 30h - 3Fh
    * The check sum is formed by the bytes following <01> (without it) to <05>
    * included, by summing (adding) the values of the bytes. Each digit is sent as
    * an ASCII code.
    * =============================================== 
    * @param string $string sum of hex bytes between 01 excluded and 05 included
    * @return string
    */
  private function getBcc($hexString){
    
     $checkSum = 0; // This will hold the sum of values of the bytes
     // $dataArray = str_split($hexString,1);
     
     $hexArray = explode(' ',$hexString);

     //Calculate the Check Sum for the passed hex bytes
     
     foreach ($hexArray as $key => $value) {
        $ascii = base_convert($value, 16, 10);  
        $checkSum += $ascii;
     }

     //Convert to array so that we can know 
     $checkSum = dechex($checkSum);
     //How many values are left to complete
     // 4 digits bits
     $checkSumArray = str_split($checkSum,1);

     // Prefix 30
     $checkSumArray = array_map(function($value){ return '3'.$value; }, $checkSumArray);

     // Make Sure everything is capital
     $checkSumArray = array_map('strtoupper', $checkSumArray);
     // Make sure we have 4 digits
     while (count($checkSumArray) < 4) {
       array_unshift($checkSumArray, '30');
     }

     return implode(' ', $checkSumArray);
   }

  /**
   * Add previx 3 for each charactor of the string]
   * @param string $string [description]
   */
   function prefix3($string){
     $prefixed_variable="";
     //Add the 30h prefix for each BCC byte
     for($i=0;$i<strlen($string);$i++){
        $prefixed_variable.=" 3".$string[$i];
     }
     return $prefixed_variable;
   }



      /**
       * @author Kamaro Lambert
       * Send receipt to SDC VIA RS232 PORT
       * RECEIPT TYPE||TRANSACTION TYPE|| RECEIPT LABEL 
       * ===========================================
       *  NORMAL     ||   SALES        ||    NS 
       *  NORMAL     ||   REFUND       ||    NR 
       *  COPY       ||   SALES        ||    CS 
       *  COPY       ||   REFUND       ||    CR 
       *  TRAINING   ||   SALES        ||    TS 
       *  TRAINING   ||   REFUND       ||    TR 
       *  PRO FORMA  ||   SALES        ||    PS 
       * 
       * -----------------------------------------------------------
       * @param  string         $Type                   Type of the receipt
       * @param  string         $mrc                   
       * @param  string         $TIN                    tax Identification Number
       * @param  string         $date_time              d/m/Y H:i:s
       * @param  integer        $receipt_number         Receipt number
       * @param  decimal(10,2)  $tax_rate_1             0.00
       * @param  decimal(10,2)  $tax_rate_2             18.00
       * @param  decimal(10,2)  $tax_rate_3             0.00
       * @param  decimal(10,2)  $tax_rate_4             0.00
       * @param  decimal(10,2)  $total_amounts_with_TAX 
       * @param  decimal(10,2)  $tax_amount_1           
       * @param  decimal(10,2)  $tax_amount_2           
       * @param  decimal(10,2)  $tax_amount_3           
       * @param  decimal(10,2)  $tax_amount_4
       *                
       * @COMMAND : C6
       * @DATA    : RtypeTTypeMRC,TIN,Date TIME, 
       *            Rnumber,TaxRate1,TaxrRate2,TaxRate3,TaxRate4,Amount1,
       *            Amount2,Amount3,Amount4,Tax1,Tax2,Tax3,Tax4
       * @EXAMPLE : nstes01012345,100600570,17/07/2013 09:29:37,
       *            1,0.00,18.00,0.00,0.00,11.00,12.00,0.00,0.00,0.00,1.83,0.00,0.00
        */
    public function sendReceipt(
          $Type="CR",
          $mrc,
          $TIN,
          $date_time,
          $receipt_number,
          $tax_rate_1="0.00",
          $tax_rate_2="18.00",
          $tax_rate_3="0.00",
          $tax_rate_4="0.00",
          $tax_amount_1="0.00",
          $tax_amount_2="0.00",
          $tax_amount_3="0.00",
          $tax_amount_4="0.00")
      {
         // $strinCommand =  $Receipt_type."$mrc,".$TIN.",$date_time,$receipt_number,$tax_rate_1,";
         // $strinCommand .= "$tax_rate_2,$tax_rate_3,$tax_rate_4,$total_amounts_with_TAX,0.00,0.00,";
         // $strinCommand .= "0.00,$tax_amount_1,$tax_amount_2,$tax_amount_3,$tax_amount_4".$ClientTin;
         $string = "nstes01012345,100600570,17/07/2013 09:29:37,15,0.00,18.00,0.00,0.00,11.00,12.00,0.00,0.00,0.00,1.83,0.00,0.00";
         $string       = $this->getCommand($string);
         
         // $string = '01 92 23 C6 4E 53 54 45 53 30 31 30 31 32 33 34 35 2C 31 30 30 36 30 30 35 37 30 2C 31 37 2F 30 37 2F 32 30 31 33 20 30 39 3A 32 39 3A 33 37 2C 31 35 2C 30 2E 30 30 2C 31 38 2E 30 30 2C 30 2E 30 30 2C 30 2E 30 30 2C 31 31 2E 30 30 2C 31 32 2E 30 30 2C 30 2E 30 30 2C 30 2E 30 30 2C 30 2E 30 30 2C 31 2E 38 33 2C 30 2E 30 30 2C 30 2E 30 30 0A 05 31 36 3B 3B 03';
         $string_array=explode(' ',$string);

         //write the first bit
         foreach ($string_array as $string_hex_dig=>$value_dig){
           $this->device->writeByte(" ".hexToByte($value_dig)."\r\n");
         }
      
         usleep(50);

         //Send request to the SDC asking the response 
         $result =  $this->device->read();
         $this->SDCController->close();
         return $result;
      }

      function requestSignature($receipt_number=26){
        $hex_array=  strToHex($receipt_number);

        //Getting data for the receipt
        $string_for_getting_bcc = $this->getLength($hex_array).' 23 C8 '.implode(' ',$hex_array).' 05';
        $checksum_values        = explode(' ', $string_for_getting_bcc);
        
        $bcc_sum                = $this->getCheckSum($checksum_values);
        $string_dig             = '01 '.$string_for_getting_bcc.' '.$this->getBcc($bcc_sum).' 03';
          
          //$string_dig=' 01 26 23 C8 38 05 30 30 30 31 38 31 03';
         $string_array=explode(' ',$string_dig);
         //write the first bit
         foreach ($string_array as $string_hex_dig=>$value_dig){
           $this->device->writeByte(" ".hexToByte($value_dig)."\r\n");
         }
             //Send request to the SDC asking the response 
         $result =  $this->device->read();
         return $result;    
        }
      
      /**
       * Get Hexa Data from the string as per RRA definition
       * @param  $string
       * @return string
       */
      public function getHexData($string)
      {
        // Convert to Bytes array
        $byte_array = unpack('C*', $string);
        
        // Get Ascii equivalent of the 
        $bytes      = array_map("chr", $byte_array);

        // Get Hex equivalent of the ascii
        $bytes      = strtoupper(bin2hex(implode($bytes)));

        return implode(' ',str_split($bytes,2));
      }


      /**
       * @author Kamaro Lambert
       * Get the command to send to SDC VIA RS232 PORT
       * 
       * @param  string $string  Receipt_typemrc,TIN,date_time,receipt_number,tax_rate_1,
       * tax_rate_2,tax_rate_3,tax_rate_4,total_amounts_with_TAX,0.00,0.00,0.00,
       * tax_amount_1,tax_amount_2,tax_amount_3,tax_amount_4ClientTin
       * @return string of hex
       */
      public function getCommand($string){
         $hex_array              = strToHex($string);
         
         //Getting the BCC string and the length of the command concatenated
         $string_for_getting_bcc = $this->getLength($hex_array).' 22 C6 '.implode(' ',$hex_array).' 05';
         
         //Calculating the checksum
         $checksum_values        = explode(' ', $string_for_getting_bcc);
         
         //Get the bcc values
         $bcc_sum                = $this->getCheckSum($checksum_values);
         
         //Maked the command
         return '01 '.$string_for_getting_bcc.' '.$this->getBcc($bcc_sum).' 03';
      }

       /**
        * Get SDC request method
        * @Author Kamaro Lambert
        * @param string data //  RtypeTTypeMRC,TIN,Date TIME, Rnumber,TaxRate1,TaxrRate2,TaxRate3,TaxRate4,Amount1,Amount2,Amount3,Amount4,Tax1,Tax2,Tax3,Tax4
        *                    // Example : "nstes01012345,100600570,17/07/2013 09:29:37,1,0.00,18.00,0.00,0.00,11.00,12.00,0.00,0.00,0.00,1.83,0.00,0.00"
        * @param string command // Command to sdc example c6
        */
        public function getSdcRequest($data = null,$command,$sequence = 20)
        {
            // Make sure ALL are in  caps
            // Prepare Data
            $data = strtoupper($data);
            $data = $this->getHexData($data);

            // Prepare command
            $request = $sequence . " " . strtoupper($command) . " 05";
            
            // if the data is not empty then add it to the command
            if (!empty($data)){
                $request = $sequence . " " . strtoupper($command) . " " . $data . " 05";
            }

            // Get the length of the byte hex to be sent
            $commandLength = $this->getLength($request);
              
            // Add length to the request
            $request = $commandLength.' '.$request;

            // Get checksum(BCC) of this command
            $commandBcc = $this->getBcc( $request);

            // For example to look for serial number you have to pass "01 24 20 E5 05 30 31 32 3E 03" OR 
            // SDC Status "01 24 20 E7 05 30 31 33 30 03"
            $request = "01 ".$request.' '.$commandBcc ." 03";

            return  $request;

        }
}