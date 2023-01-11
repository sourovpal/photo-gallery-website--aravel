<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Models\AdminSettings;
use App\Models\Images;
use App\Models\Deposits;
use App\Models\Invoices;
use App\Models\Purchases;
use App\Models\User;
use Srmklive\PayPal\Services\PayPal as PayPalClient;
use App\Helper;
use Mail;
use Carbon\Carbon;
use App\Models\PaymentGateways;

class PayPalController extends Controller
{
  use Traits\FunctionsTrait;

  public function __construct(AdminSettings $settings, Request $request) {
		$this->settings = $settings::first();
		$this->request = $request;
	}

    public function show()
    {

    if (! $this->request->expectsJson()) {
        abort(404);
    }

      // Get Payment Gateway
      $payment = PaymentGateways::findOrFail($this->request->payment_gateway);

        $feePayPal   = $payment->fee;
  			$centsPayPal =  $payment->fee_cents;

        $taxes = $this->settings->tax_on_wallet ? ($this->request->amount * auth()->user()->isTaxable()->sum('percentage') / 100) : 0;

  			$amountFixed = number_format($this->request->amount + ($this->request->amount * $feePayPal / 100) + $centsPayPal + $taxes, 2, '.', '');

        try {

          $urlSuccess = route('paypal.verify');
    			$urlCancel  = url('user/dashboard/add/funds');

        $provider = new PayPalClient();

        $token = $provider->getAccessToken();
        $provider->setAccessToken($token);
        $order = $provider->createOrder([
              "intent"=> "CAPTURE",
              'application_context' =>
                  [
                      'return_url' => $urlSuccess,
                      'cancel_url' => $urlCancel,
                      'shipping_preference' => 'NO_SHIPPING'
                  ],

              "purchase_units"=> [
                   [
                      "amount"=> [
                          "currency_code"=> $this->settings->currency_code,
                          "value"=> $amountFixed,
                          'breakdown' => [
                            'item_total' => [
                              "currency_code"=> $this->settings->currency_code,
                              "value"=> $amountFixed
                            ],
                          ],
                      ],
                       'description' => trans('misc.add_funds_desc'),

                       'items' => [
                         [
                           'name' => trans('misc.add_funds_desc'),
                            'category' => 'DIGITAL_GOODS',
                              'quantity' => '1',
                              'unit_amount' => [
                                "currency_code"=> $this->settings->currency_code,
                                "value" => $amountFixed
                              ],
                         ],
                      ],

                      'custom_id' => http_build_query([
                          'id' => auth()->id(),
                          'amount' => $this->request->amount,
                          'tax' => $this->settings->tax_on_wallet ? auth()->user()->taxesPayable() : null,
                          'mode' => 'deposit'
                      ]),
                  ],
              ],
          ]);

          return response()->json([
    					        'success' => true,
    					        'url' => $order['links'][1]['href']
    					    ]);

        } catch (\Exception $e) {

          \Log::debug($e);

          return response()->json([
            'errors' => ['error' => $e->getMessage()]
          ]);
        }
    }

    // Buy photo
    public function buy()
    {

      if (! $this->request->expectsJson()) {
          abort(404);
      }
        try {

          // Get Payment Gateway
          $payment = PaymentGateways::whereId($this->request->payment_gateway)->whereName('PayPal')->firstOrFail();

          // Get Image
    	    $image = Images::where('token_id', $this->request->token)->firstOrFail();

          $priceItem = $this->settings->default_price_photos ?: $image->price;

    			$itemPrice = $this->priceItem($this->request->license, $priceItem, $this->request->type);

          $itemName = trans('misc.'.$this->request->type.'_photo').' - '.trans('misc.license_'.$this->request->license);

          $urlSuccess = route('paypal.verify');
          $urlCancel  = url('user/dashboard/purchases');

          $provider = new PayPalClient();

          $token = $provider->getAccessToken();
          $provider->setAccessToken($token);
          $order = $provider->createOrder([
              "intent"=> "CAPTURE",
              'application_context' =>
                  [
                      'return_url' => $urlSuccess,
                      'cancel_url' => $urlCancel,
                      'shipping_preference' => 'NO_SHIPPING'
                  ],

              "purchase_units"=> [
                   [
                      "amount"=> [
                          "currency_code"=> $this->settings->currency_code,
                          "value"=> Helper::amountGross($itemPrice),
                          'breakdown' => [
                            'item_total' => [
                              "currency_code"=> $this->settings->currency_code,
                              "value"=> Helper::amountGross($itemPrice)
                            ],
                          ],
                      ],
                       'description' => $itemName,

                       'items' => [
                         [
                           'name' => $itemName,
                            'category' => 'DIGITAL_GOODS',
                              'quantity' => '1',
                              'unit_amount' => [
                                "currency_code"=> $this->settings->currency_code,
                                "value" => Helper::amountGross($itemPrice)
                              ],
                         ],
                      ],

                      'custom_id' => http_build_query([
                          'id' => $image->id,
                          'user' => auth()->id(),
                          'type' => $this->request->type,
                          'license' => $this->request->license,
                          'tax' => auth()->user()->taxesPayable() ?? null,
                          'mode' => 'sale'
                      ]),
                  ],
              ],
          ]);

          return response()->json([
    					        'success' => true,
    					        'url' => $order['links'][1]['href']
    					    ]);

        } catch (\Exception $e) {

          return response()->json([
            'errors' => ['error' => $e->getMessage()]
          ]);
        }
    }// End method buy

    public function verifyTransaction()
   {
     // Get Payment Data
     $payment = PaymentGateways::whereName('PayPal')->first();

     // Init PayPal
     $provider = new PayPalClient();
     $token = $provider->getAccessToken();
     $provider->setAccessToken($token);

     try {
       // Get PaymentOrder using our transaction ID
       $order = $provider->capturePaymentOrder($this->request->token);
       $txnId = $order['purchase_units'][0]['payments']['captures'][0]['id'];

       // Parse the custom data parameters
       parse_str($order['purchase_units'][0]['payments']['captures'][0]['custom_id'] ?? null, $data);

       if ($order['status'] && $order['status'] === "COMPLETED") {
         if ($data) {
             switch ($data['mode']) {

               //============ Start Deposit ==============
               case 'deposit':

               // Check outh POST variable and insert in DB
               $verifiedTxnId = Deposits::where('txn_id', $txnId)->first();

                 if (! isset($verifiedTxnId)) {

                  // Insert Deposit status 'Pending'
                  $this->deposit(
                    $data['id'],
                    $txnId,
                    $data['amount'],
                    'PayPal',
                    $data['tax'] ?? null
                  );
                   // Add Funds to User
                   User::find($data['id'])->increment('funds', $data['amount']);

                 }// <--- Verified Txn ID

                 return redirect('user/dashboard/add/funds');

                 break;

                 //============ Start Sale ==============
                 case 'sale':

                // Get Image
                $image = Images::whereId($data['id'])->firstOrFail();

                $priceItem = $this->settings->default_price_photos ?: $image->price;

                $itemPrice = $this->priceItem($data['license'], $priceItem, $data['type']);

                // Admin and user earnings calculation
                $earnings = $this->earningsAdminUser($image->user()->author_exclusive, $itemPrice, $payment->fee, $payment->fee_cents);

                // Check outh POST variable and insert in DB
                $verifiedTxnId = Purchases::where('txn_id', $txnId)->first();

                if (! isset($verifiedTxnId)) {
                  $this->purchase(
                    $txnId,
                    $image,
                    $data['user'],
                    $itemPrice,
                    $earnings['user'],
                    $earnings['admin'],
                    $data['type'],
                    $data['license'],
                    $earnings['percentageApplied'],
                    'PayPal',
                    $data['tax'] ?? null
                  );
                }

                  return redirect('user/dashboard/purchases');

                  break;

           }// Switch case
         }// data

         return redirect('/');
       }

     }  catch (\Exception $e) {
      \Log::debug($e);

       return redirect('/');
     }
   }// End method verifyTransaction
}
