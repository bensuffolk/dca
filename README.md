# dca.php
A simple php script designed to be run via the command line via a cron job.

This will connect to coinbase pro and buy the crypto coins specified in the config file and optionally transfer them to a crypto wallet or to a coinbase wallet.

This isa quick hacked together script that I did for myself, its not pretty but it works and does what I need it to do. I'm releasing it at the request of some people who want this functionality. If you want any changes just let me know and I will be happy to consider making them for you.

If you want to make a dontation please feel free to drop me some `ALGO` to `AXXCIFO47GA5KUC7TMGWMH4T4SP2LVXEN6HU4KL7YJ7ILJLPH7QLI7BEGQ` but don't feel obliged, I will just be happy if this helps you!


## Installation Prerequisite

Tested on Ubuntu 20.04 using php version 7.4

Besides PHP you will also need to have composer installed.


## Installation

You need to download the coinbase pro sdk from mocking-magician. The easiest way to do this is with composer as it will also install any required libraries.

```shell
git clone https://github.com/bensuffolk/dca
cd dca
composer update
```

## Configuration

There are 2 files require for your configuration, the first dca.yaml contains the API authentication details for coinbase pro. 

```
params:
  endpoint: "https://api.exchange.coinbase.com"
  key: "<COINBASE PRO KEY>"
  secret: "<COINBASE PRO SECRET>"
  passphrase: "<COINBASE PRO PASS>"
```

If you wish to use the transfer options to move your newly bought crypto to a wallet or to coinbase you will need to make sure the API details are on the Default Portfoilio.

To set up new API details go to https://pro.coinbase.com/profile/api and add a New API Key

You will then need to setup the config file dca.json to show which coins you wish to buy.

```
{
    "market": [
        "ALGO-GBP",
        "XTZ-GBP",
    ],
    "ALGO-GBP": {
        "buy-enabled": true,
        "max-buy-price": 1.25,
        "buy-amount": 2,
        "transfer-to": "wallet",
        "transfer-address": "AXXCIFO47GA5KUC7TMGWMH4T4SP2LVXEN6HU4KL7YJ7ILJLPH7QLI7BEGQ",
        "transfer-max-fee": 0.002
    },
    "XTZ-GBP": {
        "buy-enabled": true,
        "buy-fund": 2.50,
        "transfer-to": "coinbase",
        "min-transfer-balance": 2
    }
}
```

The `markets` section is an array of all coin pairs you wish to buy. I have tested this with GBP, but I see no reason why it would not work with USD etc.

Then for every market pair you will need to section to define how much you wish to buy and what to do with it. 

`buy-enabled` Must be set to true for any purchases to be made.

`max-buy-price` Optional. The maximum price you are willing to pay for this coin. If the current market price is above this no purchase will be made. 

**Note: You are buying at market price, so the price you actually pay may rise above this threshold during the actual buy transaction.** 

`buy-fund` Is how much you want to buy. You will need to specify either this or buy-amount.

`buy-amount` Is the number of coins you wish to buy. You will need to specify either this for buy-fund'. If buy-fund is specified then this option will be ignored.

`transfer-to` can either be `wallet` or `coinbase`. If you don't wish to transfer your purchased crypto anywhere then do not specify this option.

**Note: this wil transfer all of the coin in the portfolio not just the amount recently purchased.**

`transfer-address` Must be specified if trasfer-to is wallet. This is the crypto address to transfer your coin to. **Make sure this is the right address for the specified coin. Once transfered there is no getting it back**

`transfer-max-fee' Must be specified if trasfer-to is wallet. If the estimated transfer fee if greater than this amount, the trasnfer will not happen. 

`min-transfer-balance` Optional. The transfer will only happen if there are at least this number of coins in your balance.

In the above example file you can see it will purchase 2 ALGO and transfer them to a wallet, and £2.50 worth of XTZ and if there are 2 or more trasnfer them to a coinbase wallet.


## Usage

```shell
php dca.php
```

Once run the latest buy will be recorded in the dca.json file, and it will not buy again within 18 hours of the last purchase.

Once you have tested it and are happy you may want to run this via cron

```shell
crontab -e
```

For instance to run it at 7am every day you would specify the following

```
0 7 * * * /usr/bin/php /home/ben/cbp/dca.php >> /home/ben/cbp/dca.log 2>&1
```

