<?php

/**
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2021 MagnusBilling. All rights reserved.
 * ###################################
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * Please submit bug reports, patches, etc to https://github.com/magnusbilling/mbilling/issues
 * =======================================
 * Magnusbilling.com <info@magnusbilling.com>
 *
 */

/*
add the cron to check the BDService transaction status
echo "
 * * * * * php /var/www/html/mbilling/cron.php bdservice
" >> /var/spool/cron/root
 */

class TransferMobileMoneyController extends Controller
{
    private $url;
    private $amounts    = 0;
    private $user_cost  = 0;
    private $agent_cost = 0;
    private $login;
    private $token;
    private $currency;
    private $send_credit_id;
    private $user_profit;
    private $test = false;
    private $cost;
    private $showprice;
    private $sell_price;
    private $local_currency;
    public $modelTransferToMobile = array();
    public $operator_name;
    private $number;
    public $received_amout;

    public function init()
    {

        $this->modelTransferToMobile = TransferToMobile::model()->findByPk((int) Yii::app()->session['id_user']);

        $this->instanceModel = new User;
        $this->abstractModel = User::model();
        parent::init();

        $this->login    = $this->config['global']['BDService_username'];
        $this->token    = $this->config['global']['BDService_token'];
        $this->currency = $this->config['global']['BDService_cambio'];
        $this->url      = $this->config['global']['BDService_url'];
    }

    public function actionIndex($asJson = true, $condition = null)
    {

        $this->modelTransferToMobile->method = "Mobile Credit";

        if (!isset($_POST['TransferToMobile']['country']) && !isset($_POST['TransferToMobile']['method'])) {

            $this->render('selectCountry', array(
                'modelTransferToMobile' => $this->modelTransferToMobile,

            ));
            return;
        }

        if (isset($_POST['TransferToMobile']['number'])) {

            $this->number = $this->modelTransferToMobile->number = (int) $_POST['TransferToMobile']['number'];

            if (isset($this->number) && substr($this->number, 0, 2) == '00') {
                $this->number = substr($this->number, 2);
            }

            if (
                $this->number == '' || !is_numeric($this->number)
                || strlen($this->number) < 8
                || preg_match('/ /', $this->number)
            ) {
                $this->modelTransferToMobile->addError('number', Yii::t('zii', 'Number invalid, try again'));
            }
        }

        if (isset($_POST['amountValues'])) {

            $modelSendCreditProducts = SendCreditProducts::model()->findByPk((int) $_POST['amountValues']);

            $modelSendCreditRates = SendCreditRates::model()->find(array(
                'condition' => 'id_user = :key AND id_product = :key1',
                'params'    => array(
                    ':key'  => Yii::app()->session['id_user'],
                    ':key1' => $_POST['amountValues'],
                ),
            ));

            $_POST['TransferToMobile']['amountValuesBDT'] = !isset($_POST['TransferToMobile']['amountValuesBDT']) || $_POST['TransferToMobile']['amountValuesBDT'] == '' ? $modelSendCreditProducts->product : $_POST['TransferToMobile']['amountValuesBDT'];
            $_POST['TransferToMobile']['amountValuesEUR'] = !isset($_POST['TransferToMobile']['amountValuesEUR']) || $_POST['TransferToMobile']['amountValuesEUR'] == '' ? $modelSendCreditRates->sell_price : $_POST['TransferToMobile']['amountValuesEUR'];
            $_POST['TransferToMobile']['amountValues']    = $_POST['amountValues'];
        } else {
            $modelSendCreditRates = [];
        }
        //if we already request the number info, check if select a valid amount
        if (isset($_POST['TransferToMobile']['amountValuesEUR'])) {

            $this->modelTransferToMobile->method = $_POST['TransferToMobile']['method'];
            $this->modelTransferToMobile->number = $this->number;

            $this->modelTransferToMobile->amountValuesEUR = $_POST['TransferToMobile']['amountValuesEUR'];
            $this->modelTransferToMobile->amountValuesBDT = $_POST['TransferToMobile']['amountValuesBDT'];

            if (preg_match('/[A-Z][a-z]/', $_POST['TransferToMobile']['amountValuesBDT'])) {
                $this->modelTransferToMobile->addError('amountValuesBDT', Yii::t('zii', 'Invalid amount'));
            }

            if (!count($this->modelTransferToMobile->getErrors())) {

                if (!isset($_POST['TransferToMobile']['confirmed'])) {

                    $this->render('confirm', array(
                        'modelTransferToMobile' => $this->modelTransferToMobile,
                        'modelSendCreditRates'  => $modelSendCreditRates,
                    ));

                    exit;
                } else {
                    $this->confirmRefill();
                }
            }
        }
        //check the number and methods.

        $methods = [];

        $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
            'condition' => 'operator_name LIKE :key1 AND country = :key AND status = 1 AND type = :key2',
            'params'    => array(
                ':key'  => $_POST['TransferToMobile']['country'],
                ':key1' => '%Bkash%',
                ':key2' => 'Mobile Money',
            ),
        ));

        if (isset($modelSendCreditProducts[0]->id)) {
            $methods["bkash"] = "Bkash";
        }

        $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
            'condition' => 'operator_name LIKE :key1 AND country = :key AND status = 1 AND type = :key2',
            'params'    => array(
                ':key'  => $_POST['TransferToMobile']['country'],
                ':key1' => '%Rocket%',
                ':key2' => 'Mobile Money',
            ),
        ));

        if (isset($modelSendCreditProducts[0]->id)) {
            $methods["dbbl_rocket"] = "DBBL/Rocket";
        }

        $amountDetails = null;

        if (isset($_POST['TransferToMobile']['method']) && $_POST['TransferToMobile']['method'] != 'Mobile Credit') {

            if ($_POST['TransferToMobile']['method'] == '') {
                $this->modelTransferToMobile->addError('method', Yii::t('zii', 'Please select a method'));
            }

            $this->modelTransferToMobile->method = $_POST['TransferToMobile']['method'];

            if ($_POST['TransferToMobile']['method'] == 'flexiload') {
                $values = explode("-", $this->config['global']['BDService_flexiload']);
            } elseif ($_POST['TransferToMobile']['method'] == 'dbbl_rocket') {
                $values = explode("-", $this->config['global']['BDService_dbbl_rocket']);
            } elseif ($_POST['TransferToMobile']['method'] == 'bkash') {
                $values = explode("-", $this->config['global']['BDService_bkash']);
            }

            $this->actionGetProducts();

            $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
                'condition' => 'operator_name LIKE :key1 AND country = :key AND status = 1 AND type = :key2 AND product LIKE "%-%" ',
                'params'    => array(
                    ':key'  => $_POST['TransferToMobile']['country'],
                    ':key1' => '%' . $_POST['TransferToMobile']['method'] . '%',
                    ':key2' => 'Mobile Money',
                ),
            ));

            if (!isset($modelSendCreditProducts[0]->product)) {
                exit('No products found');
            }

            if (isset($modelSendCreditProducts[0]->product)) {
                $values        = explode("-", $modelSendCreditProducts[0]->product);
                $amountDetails = 'Amount (Min: ' . $values[0] . ' BDT, Max: ' . $values[1] . ' BDT)';
            } else {
                $amountDetails = '';
            }

            Yii::app()->session['allowedAmount'] = $values;

            $view                                 = 'selectAmount';
            $this->modelTransferToMobile->country = $_POST['TransferToMobile']['country'];
        } else {
            $view                                 = 'selectOperator';
            $this->modelTransferToMobile->country = $_POST['TransferToMobile']['country'];
        }

        //echo $view . "<br>";

        $this->render($view, array(
            'modelTransferToMobile' => $this->modelTransferToMobile,
            'methods'               => $methods,
            'amountDetails'         => $amountDetails,
            'post'                  => $_POST,
        ));
    }

    public function addInDataBase()
    {
        $modelSendCreditSummary                 = new SendCreditSummary();
        $modelSendCreditSummary->id_user        = Yii::app()->session['id_user'];
        $modelSendCreditSummary->service        = 'Mobile Money';
        $modelSendCreditSummary->number         = $this->number;
        $modelSendCreditSummary->confirmed      = 0;
        $modelSendCreditSummary->cost           = $this->user_cost;
        $modelSendCreditSummary->provider       = 'TanaSend';
        $modelSendCreditSummary->operator_name  = $this->operator_name;
        $modelSendCreditSummary->received_amout = 'BDT ' . $this->received_amout;
        $modelSendCreditSummary->save();
        $this->send_credit_id = $modelSendCreditSummary->id;
    }

    public function updateDataBase()
    {

        if ($this->sell_price > 0 && $this->user_cost > 0) {

            $profit = 'transfer_flexiload_profit';
            SendCreditSummary::model()->updateByPk($this->send_credit_id, array(
                'profit'         => $this->modelTransferToMobile->{$profit},
                'amount'         => $this->cost,
                'sell'           => number_format($this->sell_price, 2),
                'earned'         => number_format($this->sell_price - $this->user_cost, 2),
                'received_amout' => 'BDT ' . $this->received_amout,
            ));
        } else {
            SendCreditSummary::model()->deleteByPk($this->send_credit_id);
        }
    }

    public function sendActionTransferToMobile($action, $product = null)
    {

        $number = $this->modelTransferToMobile->number;
        $key    = time();
        $md5    = md5($this->login . $this->token . $key);

        if ($action == 'topup') {
            $modelSendCreditProducts = SendCreditProducts::model()->find(array(
                'condition' => 'operator_name = :key AND product = :key1',
                'params'    => array(
                    ':key'  => $this->modelTransferToMobile->operator,
                    ':key1' => $product,
                ),
            ));
            $this->url = "https://airtime.transferto.com/cgi-bin/shop/topup?";
            $action .= '&msisdn=number&delivered_amount_info=1&product=' . $product . '&operatorid=' . $modelSendCreditProducts->operator_id . '&sms_sent=yes';
        }

        $url = $this->url . "login=" . $this->login . "&key=$key&md5=$md5&destination_msisdn=$number&action=" . $action;

        $arrContextOptions = array(
            "ssl" => array(
                "verify_peer"      => false,
                "verify_peer_name" => false,
            ),
        );

        if (!$result = @file_get_contents($url, false, stream_context_create($arrContextOptions))) {
            $result = '';
        }

        return $result;
    }
    public function sendActionBDService()
    {

        $userBD = $this->config['global']['BDService_username'];
        $keyBD  = $this->config['global']['BDService_token'];
        $type   = $this->modelTransferToMobile->method == 'dbbl_rocket' ? 'DBBL' : $this->modelTransferToMobile->method;

        $number = preg_replace('/^00/', '', $this->modelTransferToMobile->number);
        $number = preg_replace('/^88/', '', $number);

        if ((isset($_POST['method']) && $_POST['method'] == 'bkash') || (isset($_POST['TransferToMobile']['method']) && $_POST['TransferToMobile']['method'] == 'bkash')) {
            $url = "http://takasend.org/ezzeapi/request/bkash?number=" . $number . "&amount=" . $_POST['TransferToMobile']['amountValuesBDT'] . "&type=1&id=" . $this->send_credit_id . "&user=" . $userBD . "&key=" . $keyBD . "";
        } else {

            $url = "http://takasend.org/ezzeapi/request/DBBL?number=" . $number . "&amount=" . $_POST['TransferToMobile']['amountValuesBDT'] . "&type=1&id=" . $this->send_credit_id . "&user=" . $userBD . "&key=" . $keyBD . "";
        }

        if (!$result = @file_get_contents($url, false, stream_context_create($arrContextOptions))) {
            $result = '';
        }

        return $result;
    }

    public function calculateCost($product = 0)
    {

        $methosProfit = 'transfer_flexiload_profit';

        if ($this->modelTransferToMobile->credit + $this->modelTransferToMobile->creditlimit < $this->user_cost) {

            echo '<div id="container"><div id="form"> <div id="box-4"><div class="control-group">';
            echo '<form action="" id="form1" method="POST">';
            echo '<font color=red>ERROR:You no have enough credit to transfer</font>';
            echo '</form>';
            echo '</div></div></div></div>';
            exit;
        }

        $user_profit = $this->modelTransferToMobile->{$methosProfit};

        if ($this->modelTransferToMobile->id_user > 1) {

            //check if agent have credit

            $modelAgent = User::model()->findByPk((int) $this->modelTransferToMobile->id_user);

            if ($modelAgent->credit + $modelAgent->creditlimit < $this->cost) {

                echo '<div id="container"><div id="form"> <div id="box-4"><div class="control-group">';
                echo '<form action="" id="form1" method="POST">';
                echo '<font color=red>ERROR:Your Agent no have enough credit to transfer</font>';
                echo '</form>';
                echo '</div></div></div></div>';
                exit;
            }

            $agentProfit = $modelAgent->{$methosProfit};

            $modelSendCreditProducts = SendCreditProducts::model()->findByPk((int) Yii::app()->session['id_product']);

            if (preg_match('/-/', $modelSendCreditProducts->product)) {

                $this->agent_cost = $_POST['TransferToMobile']['amountValuesBDT'] * $modelSendCreditProducts->wholesale_price *= (1 - $agentProfit / 100);
            } else {

                $this->agent_cost = $modelSendCreditProducts->wholesale_price *= (1 - $agentProfit / 100);
            }
        }
    }

    public function actionGetBuyingPrice()
    {

        $currency = $this->config['global']['BDService_cambio'];

        $rateinitial = $this->modelTransferToMobile->transfer_bdservice_rate / 100 + 1;
        //cost to send to provider selected value + admin rate * exchange
        $cost     = $_GET['amountValues'] * $rateinitial * $this->config['global']['BDService_cambio'];
        $product  = 0;
        $currency = '€';

        $methosProfit = 'transfer_flexiload_profit';
        $user_profit  = $this->modelTransferToMobile->{$methosProfit};

        $user_cost = $cost - ($cost * ($user_profit / 100));
        echo $currency . ' ' . number_format($user_cost, 2);
    }

    public function confirmRefill()
    {

        $this->user_cost = $this->getConfirmationPrice();
        $product         = 0;

        $this->calculateCost($product);

        $this->addInDataBase();

        $result = $this->sendActionBDService($this->modelTransferToMobile);

        //$result = 'SUCCESS';

        $this->checkResult($result);

        $this->updateDataBase();
        exit;
    }

    public function checkResult($result)
    {

        if (strlen($result) < 1) {

            $this->releaseCredit($result, 'error');
            exit;
        } else if (preg_match("/ERROR|error/", $result)) {
            echo '<div align=center id="container">';
            echo "<font color=red>" . $result . "</font><br><br>";
            echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
            echo '</div>';
            exit;
        } elseif (preg_match("/SUCCESS/", strtoupper($result))) {
            $this->releaseCredit($result, '');
        }
    }

    public function releaseCredit($result, $status)
    {

        $argument = $this->modelTransferToMobile->transfer_show_selling_price;

        $modelUserOld = User::model()->findByPk(Yii::app()->session['id_user']);

        if ($argument < 10) {
            $fee = '1.0' . $argument;
        } else {
            $fee = '1.' . $argument;
        }

        $this->showprice = number_format($this->cost * $fee, 2);

        if ($status == 'error') {
            $description = 'PENDING: ';
        } else {
            $description = '';
        }
        //Send Credit BDT 150 to 01630593593 via flexiload at 2.25"
        $this->received_amout = $_POST['TransferToMobile']['amountValuesBDT'];

        $description .= 'Send Credit BDT ' . $_POST['TransferToMobile']['amountValuesBDT'] . ' - ' . $this->modelTransferToMobile->number . ' via ' . $this->modelTransferToMobile->method . ' - EUR ' . number_format($_POST['TransferToMobile']['amountValuesEUR'], 2);

        /*
        echo $description . "<br>";

        echo 'remove from user ' . $this->user_cost . "<br>";
        echo 'remove from agent ' . $this->agent_cost . "<br>";

        exit;
         */
        $this->sell_price = $_POST['TransferToMobile']['amountValuesEUR'];

        if ($status != 'error') {
            User::model()->updateByPk(
                Yii::app()->session['id_user'],
                array(
                    'credit' => new CDbExpression('credit - ' . $this->user_cost),
                )
            );
        }

        $values = ":id_user, :costUser, :description, 1";
        $field  = 'id_user,credit,description,payment';

        $values .= "," . $this->send_credit_id;
        $field .= ',invoice_number';

        $sql     = "INSERT INTO pkg_refill ($field) VALUES ($values)";
        $command = Yii::app()->db->createCommand($sql);
        $command->bindValue(":id_user", Yii::app()->session['id_user'], PDO::PARAM_INT);
        $command->bindValue(":costUser", $this->user_cost * -1, PDO::PARAM_STR);
        $command->bindValue(":description", $description . '. TS Old credit ' . $modelUserOld->credit, PDO::PARAM_STR);
        $command->execute();

        $msg = $result;

        echo '<div align=center id="container">';
        echo '<font color=green>Success: ' . $msg . '</font>' . "<br><br>";
        echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
        echo '<a href="../../index.php/TransferMobileMoney/printRefill?id=' . Yii::app()->db->lastInsertID . '">Print Refill </a>' . "<br><br>";
        echo '</div>';

        if ($this->modelTransferToMobile->id_user > 1) {

            $modelAgentOld = User::model()->findByPk((int) $this->modelTransferToMobile->id_user);

            User::model()->updateByPk(
                $this->modelTransferToMobile->id_user,
                array(
                    'credit' => new CDbExpression('credit - ' . $this->agent_cost),
                )
            );

            $payment = 1;
            $values  = ":id_user, :costAgent, :description, $payment";
            $field   = 'id_user,credit,description,payment';

            $values .= ",$this->send_credit_id";
            $field .= ',invoice_number';

            $sql     = "INSERT INTO pkg_refill ($field) VALUES ($values)";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(":id_user", $this->modelTransferToMobile->id_user, PDO::PARAM_INT);
            $command->bindValue(":costAgent", $this->agent_cost * -1, PDO::PARAM_STR);
            $command->bindValue(":description", $description . '. TS Old credit ' . $modelAgentOld->credit, PDO::PARAM_STR);
            $command->execute();
        }
    }

    public function actionPrintRefill()
    {

        if (isset($_GET['id'])) {
            echo '<center>';
            $config    = LoadConfig::getConfig();
            $id_refill = $_GET['id'];

            $modelRefill = Refill::model()->findByPk((int) $id_refill, 'id_user = :key', array(':key' => Yii::app()->session['id_user']));

            echo $config['global']['fm_transfer_print_header'] . "<br><br>";

            echo $modelRefill->idUser->company_name . "<br>";
            echo $modelRefill->idUser->address . ', ' . $modelRefill->idUser->city . "<br>";
            echo "Trx ID: " . $modelRefill->id . "<br>";

            echo $modelRefill->date . "<br>";

            $number = explode(" ", $modelRefill->description);

            echo "<br>Cellulare.: " . $number[5] . "<br>";

            if (preg_match('/Meter/', $modelRefill->description)) {
                $tmp = explode('Meter', $modelRefill->description);
                echo 'Meter: ' . $tmp[1] . "<br>";
            }

            $tmp    = explode('EUR ', $modelRefill->description);
            $tmp    = explode('. T', $tmp[1]);
            $amount = $tmp[0];

            $tmp      = explode('via ', $modelRefill->description);
            $operator = strtok($tmp[1], '-');
            $tmp      = explode('Send Credit ', $modelRefill->description);
            $tmp      = explode(' -', $tmp[1]);
            $product  = $tmp[0];

            echo $product . ' ' . $operator . "<br>";

            echo "Importo: EUR <input type=text' style='text-align: right;' size='6' value='" . number_format(floatval($amount), 2) . "'> <br><br>";

            echo $config['global']['fm_transfer_print_footer'] . "<br><br>";

            echo '<td><a href="javascript:window.print()">Print</a></td><br><br>';
            echo '<td><a href="../../index.php/transferToMobile/read">Start new request</a></td>';

            echo '</center>';
        } else {
            echo ' Invalid reffil';
        }
    }

    public function actionGetProducts()
    {

        if ($_POST['TransferToMobile']['method'] == 'dbbl_rocket') {
            $_POST['TransferToMobile']['method'] = 'Rocket';
        }
        $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
            'condition' => 'operator_name LIKE :key1 AND country = :key AND status = 1 AND type = :key2',
            'params'    => array(
                ':key'  => $_POST['TransferToMobile']['country'],
                ':key1' => '%' . $_POST['TransferToMobile']['method'] . '%',
                ':key2' => 'Mobile Money',
            ),
        ));

        if (!isset($modelSendCreditProducts[0]->id)) {
            exit('There not product to ' . $_POST['TransferToMobile']['country'] . ' method ' . $_POST['TransferToMobile']['method'] . ' to Mobile Money');
        }

        $operatorId = $modelSendCreditProducts[0]->operator_id;

        $ids_products = array();
        foreach ($modelSendCreditProducts as $key => $products) {
            $ids_products[] = $products->id;
        }
        //get the user prices to mount the amount combo
        $criteria = new CDbCriteria();
        $criteria->addInCondition('id_product', $ids_products);
        $criteria->addCondition('id_user = :key');
        $criteria->params[':key'] = Yii::app()->session['id_user'];

        $modelSendCreditRates = SendCreditRates::model()->findAll($criteria);

        $values = array();
        $i      = 0;

        foreach ($modelSendCreditProducts as $key => $product) {

            if (is_numeric($product->product)) {
                $values[trim($product->id)]        = '<font size=1px>' . $product->currency_dest . '</font> ' . trim($product->product) . ' = <font size=1px>' . $product->currency_orig . '</font> ' . number_format(trim($modelSendCreditRates[$i]->sell_price), 2);
                Yii::app()->session['is_interval'] = false;
            } else {
                Yii::app()->session['is_interval']                 = true;
                Yii::app()->session['interval_currency']           = $product->currency_dest;
                Yii::app()->session['interval_product_id']         = $product->id;
                Yii::app()->session['interval_product_interval']   = $product->product;
                Yii::app()->session['interval_product_sell_price'] = trim($modelSendCreditRates[$i]->sell_price);

                Yii::app()->session['allowedAmount'] = explode('-', $product->product);
            }
            $i++;
        }

        Yii::app()->session['amounts']    = $values;
        Yii::app()->session['operatorId'] = $operatorId;
    }

    public function getConfirmationPrice()
    {

        $methosProfit = 'transfer_flexiload_profit';
        $user_profit  = $this->modelTransferToMobile->{$methosProfit};

        $modelSendCreditProducts = SendCreditProducts::model()->findByPk((int) Yii::app()->session['id_product']);
        $modelSendCreditRates    = SendCreditRates::model()->find(array(
            'condition' => 'id_product = :key AND id_user = :key1',
            'params'    => array(
                ':key'  => $modelSendCreditProducts->id,
                ':key1' => Yii::app()->session['id_user'],
            ),
        ));

        $this->operator_name = $modelSendCreditRates->idProduct->operator_name;

        $this->cost = $modelSendCreditProducts->wholesale_price;
        if (preg_match('/-/', $modelSendCreditProducts->product)) {

            $this->user_cost = $_POST['TransferToMobile']['amountValuesBDT'] * $modelSendCreditProducts->wholesale_price *= (1 - $user_profit / 100);
        } else {

            $this->user_cost = $modelSendCreditProducts->wholesale_price *= (1 - $user_profit / 100);
        }

        return $this->user_cost;
    }

    public function actionGetBuyingPriceDBService($method = '', $valueAmoutEUR = '', $valueAmoutBDT = '', $country = '')
    {

        $method = $method == '' ? $_GET['method'] : $method;
        $method = $method == 'dbbl_rocket' ? 'Rocket' : $method;

        $methosProfit = 'transfer_flexiload_profit';

        $user_profit = $this->modelTransferToMobile->{$methosProfit};

        if (isset($_GET['id'])) {
            //calculation the R button value.

            $modelSendCreditProducts = SendCreditProducts::model()->findByPk((int) $_GET['id']);

            //wholesale price - cliente discount;
            echo $amount                      = $modelSendCreditProducts->wholesale_price *= (1 - $user_profit / 100);
            Yii::app()->session['id_product'] = $modelSendCreditProducts->id;
            Yii::app()->session['amount']     = $amount;
            exit;
        } else {

            $amountEUR = $valueAmoutEUR == '' ? $_GET['valueAmoutEUR'] : $valueAmoutEUR;
            $amountBDT = $valueAmoutBDT == '' ? $_GET['valueAmoutBDT'] : $valueAmoutBDT;

            $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
                'condition' => 'UPPER(operator_name) LIKE :key AND product LIKE "%-%"',
                'params'    => array(':key' => strtoupper($country . ' ' . $method) . '%'),
            ));

            foreach ($modelSendCreditProducts as $key => $value) {

                $product = explode('-', $value->product);
                if ($amountBDT >= $product[0] && $amountBDT <= $product[1]) {
                    $product = $value;
                    break;
                }
            }

            $modelSendCreditRates = SendCreditRates::model()->find(array(
                'condition' => 'id_user = :key',
                'params'    => array(
                    ':key'  => Yii::app()->session['id_user'],
                    ':key1' => $product->product,
                ),
                'with'      => array(
                    'idProduct' => array(
                        'condition' => 'product =  :key1 AND operator_id = 0',
                    ),
                ),
            ));

            $amount = $amountBDT * $product->wholesale_price;

            //wholesale price - cliente discount;
            echo $amount                      = $amount *= (1 - $user_profit / 100);
            Yii::app()->session['id_product'] = (int) $product->id;
            Yii::app()->session['amount']     = $amount;
            exit;
        }
    }

    public function actionConvertCurrency()
    {
        $method = $_GET['method'];
        $method = $method == 'dbbl_rocket' ? 'Rocket' : $method;

        $methosProfit = 'transfer_flexiload_profit';

        $user_profit = $this->modelTransferToMobile->{$methosProfit};

        $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
            'condition' => 'UPPER(operator_name) LIKE :key AND product LIKE "%-%"',
            'params'    => array(':key' => strtoupper('Bangladesh ' . $method) . '%'),
        ));

        if ($_GET['currency'] == 'EUR') {

            /*
            Request 2: to Send EUR 2.00, will show Selling price EUR 2.00 and BDT amount converted to BDT 125 to
            send(2.00-0.75/0.01).
            If click on "R", will show EUR 1.25.
             */

            $amountEUR = $_GET['amount'];

            $amountBDT                        = $amountEUR / ($modelSendCreditProducts[0]->retail_price);
            Yii::app()->session['id_product'] = (int) $modelSendCreditProducts[0]->id;

            echo $amount = number_format($amountBDT, 0, '', '');
        } else {

            /*
            Request 1: to Send BDT 150, will show Selling price EUR 2.25(150*0.01+0.75). If click on "R", will show EUR 1.5.
             */
            $amountBDT = $_GET['amount'];

            foreach ($modelSendCreditProducts as $key => $value) {
                $product = explode('-', $value->product);
                if ($amountBDT >= $product[0] && $amountBDT <= $product[1]) {
                    $product = $value;
                    break;
                }
            }

            if (!isset($product->product)) {
                exit('invalid');
            }

            $modelSendCreditRates = SendCreditRates::model()->find(array(
                'condition' => 'id_user = :key',
                'params'    => array(
                    ':key'  => Yii::app()->session['id_user'],
                    ':key1' => $product->product,
                ),
                'with'      => array(
                    'idProduct' => array(
                        'condition' => 'product =  :key1 AND operator_id = 0',
                    ),
                ),
            ));
            Yii::app()->session['id_product'] = (int) $modelSendCreditRates->id_product;
            echo $amount                      = number_format($modelSendCreditRates->sell_price * $amountBDT, 2);
        }
    }

    public function actionGetProductTax()
    {

        $modelSendCreditProducts = SendCreditProducts::model()->findByPk((int) $_GET['id']);
        echo $modelSendCreditProducts->info;
    }

    public function actionSetProductId()
    {
        Yii::app()->session['id_product'] = (int) $_GET['id'];
    }
}
