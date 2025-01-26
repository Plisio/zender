<?php

return [
    "default" => function(&$request){
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));

        $plugin = $this->system->getPlugin($request["name"], "directory");

        if(!$plugin)
            $this->pluginError(__("lang_plugin_generic_unabletoprocess"));

        $pluginData = json_decode($plugin["data"], true);

        $getItem = $this->system->getOrder(logged_id, "uid");

        if(!$getItem)
            $this->pluginError(__("lang_plugin_generic_unabletoprocess"));

        $order = json_decode($getItem["data"], true);

        try {
            $generatedNumber = $this->hash->encode(logged_id, system_token) . time();
            $params = [
                "api_key" => $pluginData["apikey"],
                "order_number" => $generatedNumber,
                "order_name" => "Order # " . $generatedNumber,
                "source_amount" => $order["data"]["original_price"],
                "source_currency" => $order["data"]["base_currency"],
                "callback_url" => site_url("plugin?name={$request["name"]}&hash={$order["data"]["user"]["hash"]}&action=callback&json=true", true),
                "cancel_url" => site_url("plugin?name={$request["name"]}&hash={$order["data"]["user"]["hash"]}&action=cancel", true),
                "success_url" => site_url("plugin?name={$request["name"]}&hash={$order["data"]["user"]["hash"]}&action=success", true),
                "email" => $order["data"]["user"]["email"],
                "plugin" => "Zender",
                "version" => "1.0.0"
                ];

            $createOrder = $this->guzzle->get("https://api.plisio.net/api/v1/invoices/new", [
                "query" => $params,
                "allow_redirects" => true,
                "http_errors" => false
            ]);

            $orderResponse = json_decode($createOrder->getBody()->getContents(), true);

            if ($orderResponse["status"] !== "error" && !empty($orderResponse['data'])):
                $this->header->redirect($orderResponse["data"]["invoice_url"]);
            else:
                $this->pluginError(implode(',', json_decode($order['data']['message'], true)));
            endif;
        } catch (Exception $e) {
            $this->pluginError(___(__("lang_plugin_generic_wentwrongerr"), [$e->getMessage()]));
        }
    },
    "success" => function(&$request){
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));

        $vars = [
            "title" => __("lang_title_payment_success"),
            "page" => "misc/payment",
            "data" => [
            	"message" => __("lang_and_dash_pg_pay_line45")
            ]
        ];

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(__DIR__ . "/views/success.tpl");
        $this->smarty->display(template . "/footer.tpl");
    },
    "cancel" => function(&$request){
        if(!$this->session->has("logged"))
            $this->header->redirect(site_url("dashboard/auth"));
        
        $vars = [
            "title" => __("lang_title_payment_cancel"),
            "page" => "misc/payment",
            "data" => [
            	"message" => __("lang_body_payment_cancel")
            ]
        ];

        $this->smarty->assign($vars);
        $this->smarty->display(template . "/header.tpl");
        $this->smarty->display(__DIR__ . "/views/cancel.tpl");
        $this->smarty->display(template . "/footer.tpl");
    },
    "callback" => function(&$request){
        if(!isset($request["hash"], $request["txn_id"], $request["json"]))
            response(500);

        $getItem = $this->system->getOrder($request["hash"], "hash");

        if(!$getItem)
            response(404);

        $item = json_decode($getItem["data"], true);
        $user = $this->system->getUser($item["data"]["user"]["id"]);
        $txn = $request["txn_id"];

        if(!$user)
            response(500);

        if(($request["status"] != "completed") || ($request["status"] != "mismatch"))
            response(500);

        $this->system->delete($getItem["uid"], false, "orders");

        if($item["type"] < 2):
            if($this->system->checkSubscription($user["id"]) > 0):
                $transaction = $this->system->create("transactions", [
                    "uid" => $user["id"],
                    "pid" => $item["data"]["package"]["id"],
                    "type" => 1,
                    "price" => $item["data"]["original_price"],
                    "currency" => system_currency,
                    "duration" => $item["data"]["duration"],
                    "provider" => "plisio",
                    "txn" => $txn
                ]);

                $filtered = [
                    "pid" => $item["data"]["package"]["id"],
                    "tid" => $transaction
                ];

                $subscription = $this->system->getSubscription(false, $user["id"]);

                $this->system->update($subscription["sid"], $user["id"], "subscriptions", $filtered);
            else:
                $transaction = $this->system->create("transactions", [
                    "uid" => $user["id"],
                    "pid" => $item["data"]["package"]["id"],
                    "type" => 1,
                    "price" => $item["data"]["original_price"],
                    "currency" => system_currency,
                    "duration" => $item["data"]["duration"],
                    "provider" => "plisio",
                    "txn" => $txn
                ]);

                $filtered = [
                    "uid" => $user["id"],
                    "pid" => $item["data"]["package"]["id"],
                    "tid" => $transaction
                ];

                $this->system->create("subscriptions", $filtered);
            endif;

        else:
            $transaction = $this->system->create("transactions", [
                "uid" => $user["id"],
                "pid" => 0,
                "type" => 2,
                "price" => $item["data"]["credits"],
                "currency" => system_currency,
                "duration" => 0,
                "provider" => "plisio",
                "txn" => $txn
            ]);

            $this->system->credits($user["id"], "increase", $item["data"]["credits"]);
        endif;

        $this->cache->container("system.transactions");
        $this->cache->clear();
    }
];