<?php

/*
MIT License

Copyright (c) 2021 Ben Suffolk

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/


require 'vendor/autoload.php';

use MockingMagician\CoinbaseProSdk\CoinbaseFacade;
use MockingMagician\CoinbaseProSdk\Functional\Build\MarketOrderToPlace;
use MockingMagician\CoinbaseProSdk\Functional\Websocket\Message\LastMatchMessage;
use MockingMagician\CoinbaseProSdk\Functional\Websocket\Message\DoneMessage;
use MockingMagician\CoinbaseProSdk\Functional\Websocket\Message\ErrorMessage;
use MockingMagician\CoinbaseProSdk\Functional\Websocket\Websocket;
use MockingMagician\CoinbaseProSdk\Functional\Websocket\WebsocketRunner;

define('MODE_STARTING', 0);
define('MODE_BUY', 1);
define('MODE_BOUGHT', 3);
define('MODE_NO_BUY', 4);
define('MODE_DONE', 5);

foreach(get_config('market') as $market)
{
 $GLOBALS[$market]['mode'] = MODE_STARTING;
}


// Open the API
$GLOBALS['api'] = CoinbaseFacade::createCoinbaseApiFromYaml(dirname(__FILE__).'/dca.yaml');

refresh_balances();

$websocket = $GLOBALS['api']->websocket();;

$subscriber = $websocket->newSubscriber();
$subscriber->setProductIds(get_config('market'));

$subscriber->activateChannelMatches(TRUE);
$subscriber->activateChannelUser(TRUE);
$subscriber->activateChannelHeartbeat(TRUE);


$websocket->run($subscriber, function($runner)
{
 while($runner->isConnected())
 {
  $message = $runner->getMessage();

  if($message instanceof LastMatchMessage)
  {
   $market = $message->getProductId();
   $price = $message->getPayload()['price'];

   echo sprintf("%8s Current Market Price: %f\n", $market, $price);

   // Should we buy?
   if(!get_config('buy-enabled', $market))
   {
    $GLOBALS[$market]['mode'] = MODE_NO_BUY;
    continue;
   }

   $last_buy = get_config('last-buy', $market);
    
   // Make sure we have not already bought recently
   if($last_buy > 0 && $last_buy + (60*60*18) > time())
   {
    $GLOBALS[$market]['mode'] = MODE_NO_BUY;
    continue;
   }

   // Make sure we have enough balance
   list($coin, $currency) = explode('-', $market);

   if(get_config('buy-fund', $market) > $GLOBALS['balance'][$currency])
   {
    error_log("Not enough balance of ".$currency." to buy ".$coin);
    $GLOBALS[$market]['mode'] = MODE_NO_BUY;
    continue;
   }

   if(get_config('buy-amount', $market))
   {
    market_buy($market, get_config('buy-amount', $market));
   }
   else if(get_config('buy-fund', $market))
   {
    market_buy($market, NULL, get_config('buy-fund', $market));
   }

   continue;
  }

  if($message instanceof DoneMessage)
  {
   process_filled_order($message);
   continue;
  }

  if($message instanceof ErrorMessage)
  {
   $message_ = $message->getMessage();
   $reason = $message->getReason();
      
   throw new Exception("$message_. $reason");
  }

  // Check if we are done
  $keep_running = false;
  foreach(get_config('market') as $market)
  {
   // We are all done with this coin
   if($GLOBALS[$market]['mode'] == MODE_DONE)
   {
    continue;
   }

   // Not finished buying
   if($GLOBALS[$market]['mode'] < MODE_BOUGHT)
   {
    $keep_running = TRUE;
    continue;
   }
   
   // See if we need to transfer
   if(get_config('transfer-to', $market) != FALSE)
   {
    if(get_config('transfer-to', $market) === 'coinbase')
    {
     withdraw_to_cb($market);
    }
    else if(get_config('transfer-to', $market) === 'wallet' && get_config('transfer-address', $market) != FALSE)
    {
     withdraw_to_wallet($market, get_config('transfer-address', $market), get_config('transfer-max-fee', $market));
    }
   }
  }
  
  if($keep_running == FALSE)
  {
   exit;
  }
 }
});


function load_config()
{
 $GLOBALS['config'] = json_decode(file_get_contents(dirname(__FILE__).'/dca.json'), TRUE);
 
 if(is_null($GLOBALS['config']))
 {
  error_log('Error loading config file: '.json_last_error_msg());
  exit;
 }
}


function save_config()
{
 file_put_contents(dirname(__FILE__).'/dca.json', json_encode($GLOBALS['config'], JSON_PRETTY_PRINT));
}


function get_config($key, $market = NULL)
{
 if(empty($GLOBALS['config']))
 {
  load_config();
 }
 
 if($market == NULL)
 {
  if(empty($GLOBALS['config'][$key]) === FALSE)
  {
   // Special case, market should return an array
   if($key === 'market')
   {
    return is_array($GLOBALS['config']['market'])?$GLOBALS['config']['market']:array($GLOBALS['config']['market']);
   }
   
   return $GLOBALS['config'][$key];
  }
 }
 else if(empty($GLOBALS['config'][$market]) === FALSE && is_array($GLOBALS['config'][$market]))
 {
  if(empty($GLOBALS['config'][$market][$key]) === FALSE && is_array($GLOBALS['config'][$market][$key]) === FALSE)
  {
   return $GLOBALS['config'][$market][$key];
  }
 }

 return FALSE;
}


function market_buy($market, $quantity = NULL, $fund = NULL)
{
 $marketOrder = CoinbaseFacade::createMarketOrderToPlace(MarketOrderToPlace::SIDE_BUY, $market, $quantity, $fund);

 $GLOBALS['api']->orders()->placeOrder($marketOrder);

 $GLOBALS[$market]['mode'] = MODE_BUY;

 echo sprintf("%s buy ordered placed\n", $market);
}


function process_filled_order($order)
{
 $id = $order->getOrderId();
 $market = $order->getProductId();
 
 try
 {
  $order = $GLOBALS['api']->orders()->getOrderById($id);
 }
 catch(Exception $e)
 {
  error_log($e->getMessage());
  return;
 }

 $id = $order->getId();
 $market = $order->getProductId();
 $size = $order->getFilledSize();
 $fees = $order->getFillFees();
 $filled_price = $order->getExecutedValue() / $order->getFilledSize();

 if($order->getSide() === 'buy')
 {
  $GLOBALS[$market]['mode'] = MODE_BOUGHT;
 
  $total_cost = $order->getExecutedValue() + $order->getFillFees();
  $total_filled_price = $total_cost / $order->getFilledSize();

  echo sprintf("%f %s purchased at %f, Fees: %f, effective price: %f, actual: %f\n", $size, $market, $filled_price, $fees, $total_filled_price, $total_cost);

  $GLOBALS['config'][$market]['last-buy'] = time();
  $GLOBALS['config'][$market]['last-fill-price'] = $filled_price;
  $GLOBALS['config'][$market]['last-fill-quantity'] = $size;

  save_config();
 }
 else if($order->getSide() === 'sell')
 {
  $total = $order->getExecutedValue() - $order->getFillFees();
  echo sprintf("%f %s sold at %f, Fees: %f, actual: %f\n", $size, $market, $filled_price, $fees, $total);
 }
 
 refresh_balances();
}


function refresh_balances()
{
 try
 {
  foreach($GLOBALS['api']->accounts()->list() as $account)
  {
   $GLOBALS['balance'][$account->getCurrency()] = $account->getBalance();
  }
 }
 catch(Exception $e)
 {
  error_log($e->getMessage());
  return;
 }

 echo "\nBalance\n=======\n";

 $printed = array();
 foreach(get_config('market') as $market)
 {
  foreach(explode('-', $market) as $coin)
  {
   if(!in_array($coin, $printed))
   {
    echo sprintf("%4s: %f\n", $coin, $GLOBALS['balance'][$coin]);
    $printed[] = $coin;
   }
  }
 }
 
 if(count($printed))
 {
  echo "\n";
 }
}


function withdraw_to_cb($market, $amount=0)
{
 list($coin, $currency) = explode('-', $market);

 $id = NULL;

 // Find the right account
 foreach($GLOBALS['api']->coinbaseAccounts()->listCoinbaseAccounts() as $account)
 {
  if($account->getCurrency() === $coin)
  {
   $id = $account->getId();
   break;
  }
 }

 if(is_null($id))
 {
  error_log("Unable to find Coinbase account for ".$market);
  return;
 }
 
 if($amount == 0 || $amount > $GLOBALS['balance'][$coin])
 {
  $amount = $GLOBALS['balance'][$coin];
 }
 
 if($amount == 0)
 {
  $GLOBALS[$market]['mode'] = MODE_DONE;
  return;
 }
 
 try
 {
  echo sprintf("Transfering %f %s to Coinbase\n", $amount, $coin);
  $results = $GLOBALS['api']->withdrawals()->doWithdrawToCoinbase($amount, $coin, $id);
  $GLOBALS[$market]['mode'] = MODE_DONE;
 }
 catch(Exception $e)
 {
  error_log($e->getMessage());
  return;
 }
 
 refresh_balances();
}


function withdraw_to_wallet($market, $address, $max_fee = FALSE, $amount=0)
{
 list($coin, $currency) = explode('-', $market);
 
 if($amount == 0 || $amount > $GLOBALS['balance'][$coin])
 {
  $amount = $GLOBALS['balance'][$coin];
 }
 
 if($amount == 0)
 {
  $GLOBALS[$market]['mode'] = MODE_DONE;
  return;
 }
 
 try
 {
  $fee = $GLOBALS['api']->withdrawals()->getFeeEstimate($coin, $address);

  if($max_fee && $fee > $max_fee)
  {
   echo sprintf("Transfer fee %f is too expensve for %s\n", $fee, $coin);
   $GLOBALS[$market]['mode'] = MODE_DONE;
   return;
  }
  
  echo sprintf("Transfering %f %s to %s with an estimated fee of %f\n", $amount, $coin, $address, $fee);

  $GLOBALS['api']->withdrawals()->doWithdrawToCryptoAddress($amount, $coin, $address);
  $GLOBALS[$market]['mode'] = MODE_DONE;
 }
 catch(Exception $e)
 {
  error_log($e->getMessage());
  return;
 }
 
 refresh_balances();
}
