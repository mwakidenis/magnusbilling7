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

class TransferToMobileController extends Controller
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

    public function init()
    {

        if (isset($_POST['TransferToMobile']['number']) && $_POST['TransferToMobile']['number'] == '5551982464731') {
            $this->test = true;
        }

        if (isset($_POST['TransferToMobile']['number']) && substr($_POST['TransferToMobile']['number'], 0, 2) == '00') {
            $_POST['TransferToMobile']['number'] = substr($_POST['TransferToMobile']['number'], 2);
        }

        $this->instanceModel = new User;
        $this->abstractModel = User::model();
        parent::init();

        if (isset($_POST['TransferToMobile']['method'])) {

            if ($_POST['TransferToMobile']['method'] == 'international') {
                $this->login    = $this->config['global']['fm_transfer_to_username'];
                $this->token    = $this->config['global']['fm_transfer_to_ token'];
                $this->currency = $this->config['global']['fm_transfer_currency'];
                $this->url      = 'https://fm.transfer-to.com/cgi-bin/shop/topup?';
            } else {
                $this->login    = $this->config['global']['BDService_username'];
                $this->token    = $this->config['global']['BDService_token'];
                $this->currency = $this->config['global']['BDService_cambio'];
                $this->url      = $this->config['global']['BDService_url'];
            }
        }

        $this->modelTransferToMobile = TransferToMobile::model()->findByPk((int) Yii::app()->session['id_user']);
    }

    public function actionRead($asJson = true, $condition = null)
    {
        if (isset($_POST['amountValues'])) {
            $_POST['TransferToMobile']['amountValues'] = $_POST['amountValues'];
        }
        //if we already request the number info, check if select a valid amount
        if (isset($_POST['TransferToMobile']['amountValues']) || isset($_POST['TransferToMobile']['amountValuesEUR'])) {

            $this->modelTransferToMobile->method = $_POST['TransferToMobile']['method'];
            $this->modelTransferToMobile->number = $_POST['TransferToMobile']['number'];
            if ($this->modelTransferToMobile->method == 'international') {
                $this->modelTransferToMobile->country  = $_POST['TransferToMobile']['country'];
                $this->modelTransferToMobile->operator = $_POST['TransferToMobile']['operator'];
            }
            if ($this->modelTransferToMobile->method == 'international') {

                if (isset($_POST['TransferToMobile']['amountValuesBDT'])) {
                    $this->modelTransferToMobile->amountValues = Yii::app()->session['interval_product_id'];
                    $_POST['TransferToMobile']['amountValues'] = Yii::app()->session['interval_product_id'];
                } else {
                    $this->modelTransferToMobile->amountValues = $_POST['TransferToMobile']['amountValues'];
                }
            }

            if ($this->modelTransferToMobile->method != 'international') {
                //check min max
                $min = Yii::app()->session['allowedAmount'][0];
                $max = Yii::app()->session['allowedAmount'][1];
                // echo '<pre>';

                if ($_POST['TransferToMobile']['amountValuesBDT'] < $min) {
                    $this->modelTransferToMobile->addError('amountValuesBDT', Yii::t('zii', 'Amount is < then minimal allowed'));
                } else if ($_POST['TransferToMobile']['amountValuesBDT'] > $max) {
                    $this->modelTransferToMobile->addError('amountValuesBDT', Yii::t('zii', 'Amount is > then maximum allowed'));
                }
                $this->modelTransferToMobile->amountValuesEUR = $_POST['TransferToMobile']['amountValuesEUR'];
                $this->modelTransferToMobile->amountValuesBDT = $_POST['TransferToMobile']['amountValuesBDT'];
            }

            if ($this->modelTransferToMobile->method != 'international' && preg_match('/[A-Z][a-z]/', $_POST['TransferToMobile']['amountValuesBDT'])) {
                $this->modelTransferToMobile->addError('amountValuesBDT', Yii::t('zii', 'Invalid amount'));
            } elseif ($this->modelTransferToMobile->method == 'international' && !is_numeric($_POST['TransferToMobile']['amountValues'])) {
                $this->modelTransferToMobile->addError('amountValues', Yii::t('zii', 'Invalid amount'));
            } elseif (!count($this->modelTransferToMobile->getErrors())) {

                if (!isset($_POST['TransferToMobile']['confirmed'])) {

                    if ($this->modelTransferToMobile->method == 'international') {

                        $modelSendCreditRates = SendCreditRates::model()->find(array(
                            'condition' => 'id_user = :key',
                            'params'    => array(
                                ':key'  => Yii::app()->session['id_user'],
                                ':key1' => $_POST['TransferToMobile']['amountValues'],
                            ),
                            'with'      => array(
                                'idProduct' => array(
                                    'condition' => 'idProduct.id =  :key1',
                                ),
                            ),
                        ));
                        $this->render('confirmInternational', array(
                            'modelTransferToMobile' => $this->modelTransferToMobile,
                            'modelSendCreditRates'  => $modelSendCreditRates,
                            'post'                  => $_POST,
                        ));
                    } else {
                        $this->render('confirmBDService', array(
                            'modelTransferToMobile' => $this->modelTransferToMobile,
                        ));
                    }

                    exit;
                } else {
                    $this->confirmRefill();
                }
            }
        }
        //check the number and methods.
        elseif (isset($_POST['TransferToMobile']['method'])) {
            if ($_POST['TransferToMobile']['method'] == '') {
                $this->modelTransferToMobile->addError('method', Yii::t('zii', 'Please select a method'));
            }

            if (
                $_POST['TransferToMobile']['number'] == '' || !is_numeric($_POST['TransferToMobile']['number'])
                || strlen($_POST['TransferToMobile']['number']) < 8
                || preg_match('/ /', $_POST['TransferToMobile']['number'])
            ) {
                $this->modelTransferToMobile->addError('number', Yii::t('zii', 'Number invalid, try again'));
            }

            $this->modelTransferToMobile->method = $_POST['TransferToMobile']['method'];
            $this->modelTransferToMobile->number = $_POST['TransferToMobile']['number'];

            if ($_POST['TransferToMobile']['method'] == 'international') {
                //if ok, request number information
                if (!count($this->modelTransferToMobile->getErrors())) {
                    $this->actionMsisdn_info();
                }
            }
        }

        $methods = [];
        if ($this->modelTransferToMobile->transfer_international) {
            $methods["international"] = "International";
        }
        if ($this->modelTransferToMobile->transfer_flexiload) {
            $methods["flexiload"] = "Flexiload";
        }
        if ($this->modelTransferToMobile->transfer_bkash) {
            $methods["bkash"] = "Bkash";
        }
        if ($this->modelTransferToMobile->transfer_dbbl_rocket) {
            $methods["dbbl_rocket"] = "DBBL/Rocket";
        }

        $view = !isset($_POST['TransferToMobile']['method']) || $_POST['TransferToMobile']['method'] == 'international' ? 'transferToMobile' : 'bDService';

        $amountDetails = null;

        if (isset($_POST['TransferToMobile']['method']) && strlen($_POST['TransferToMobile']['method']) && $_POST['TransferToMobile']['method'] != 'international') {

            if ($_POST['TransferToMobile']['method'] == 'flexiload') {
                $values = explode("-", $this->config['global']['BDService_flexiload']);
            } elseif ($_POST['TransferToMobile']['method'] == 'dbbl_rocket') {
                $values = explode("-", $this->config['global']['BDService_dbbl_rocket']);
            } elseif ($_POST['TransferToMobile']['method'] == 'bkash') {
                $values = explode("-", $this->config['global']['BDService_bkash']);
            }
            Yii::app()->session['allowedAmount'] = $values;
            $amountDetails                       = 'Amount (Min: ' . $values[0] . ' BDT, Max: ' . $values[1] . ' BDT)';
        }
        if (count($methods)) {
            $this->render($view, array(
                'modelTransferToMobile' => $this->modelTransferToMobile,
                'methods'               => $methods,
                'amountDetails'         => $amountDetails,
            ));
        } else {
            echo '<div align=center id="container">';
            echo '<font color=red>Not available any refill method for you</font>';
            echo '</div>';
            exit;
        }
    }

    public function addInDataBase()
    {
        $modelSendCreditSummary            = new SendCreditSummary();
        $modelSendCreditSummary->id_user   = Yii::app()->session['id_user'];
        $modelSendCreditSummary->service   = $_POST['TransferToMobile']['method'];
        $modelSendCreditSummary->number    = $_POST['TransferToMobile']['number'];
        $modelSendCreditSummary->confirmed = $_POST['TransferToMobile']['method'] == 'international' ? 1 : 0;
        $modelSendCreditSummary->cost      = $this->user_cost;
        $modelSendCreditSummary->save();
        $this->send_credit_id = $modelSendCreditSummary->id;
    }

    public function updateDataBase()
    {

        if ($this->sell_price > 0 && $this->user_cost > 0) {

            $profit = 'transfer_' . $_POST['TransferToMobile']['method'] . '_profit';
            SendCreditSummary::model()->updateByPk($this->send_credit_id, array(
                'profit' => $this->modelTransferToMobile->{$profit},
                'amount' => $this->cost,
                'sell'   => number_format($this->sell_price, 2),
                'earned' => number_format($this->sell_price - $this->user_cost, 2),
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

        if ($this->test == true && preg_match('/topup/', $action)) {
            return $result = 'transactionid=662680426
msisdn=number
destination_msisdn=5551982464731
country=Brazil
countryid=691
operator=Vivo Brazil
operatorid=708
reference_operator=55674
originating_currency=EUR
destination_currency=BRL
product_requested=7
actual_product_sent=7
wholesale_price=2.40
retail_price=2.40
balance=1320.90
sms_sent=yes
sms=
cid1=
cid2=
cid3=
local_info_value=7
local_info_amount=7
local_info_currency=BRL
authentication_key=1529417078
error_code=0
error_txt=Transaction successful';
        } else {

            if (!$result = @file_get_contents($url, false, stream_context_create($arrContextOptions))) {
                $result = '';
            }
        }

        if ($this->test == true) {
            //echo $url . "<br>";
        }

        return $result;
    }
    public function sendActionBDService()
    {

        $type = $this->modelTransferToMobile->method == 'dbbl_rocket' ? 'DBBL' : $this->modelTransferToMobile->method;

        $url = $this->url . "/ezzeapi/request/" . $type . "?number=" . $this->modelTransferToMobile->number . "&amount=" . $_POST['TransferToMobile']['amountValuesBDT'] . "&type=1&id=" . $this->send_credit_id . "&user=" . $this->login . "&key=" . $this->token;

        if ($this->test == true) {
            $result = 'SUCCESS';
        } else {
            if (!$result = @file_get_contents($url, false, stream_context_create($arrContextOptions))) {
                $result = '';
            }
        }

        return $result;
    }

    public function calculateCost($product = 0)
    {

        $methosProfit = 'transfer_' . $_POST['TransferToMobile']['method'] . '_profit';

        if ($this->test == true) {
            echo "<br>" . 'cost=' . $this->cost . ' - prodict=' . $product . "<br>";
        }

        if ($this->modelTransferToMobile->credit + $this->modelTransferToMobile->creditlimit < $this->cost) {

            echo '<div id="container"><div id="form"> <div id="box-4"><div class="control-group">';
            echo '<form action="" id="form1" method="POST">';
            echo '<font color=red>ERROR:You no have enough credit to transfer</font>';
            echo '</form>';
            echo '</div></div></div></div>';
            exit;
        }

        $user_profit = $this->modelTransferToMobile->{$methosProfit};

        $this->user_cost = $this->cost - ($this->cost * ($user_profit / 100));

        $this->user_profit = $this->cost * ($user_profit / 100);

        if ($this->test == true) {
            echo 'cost=' . $this->cost . ', user_profit= ' . $this->cost * ($this->user_profit / 100) . ' user_profit=' . $this->user_profit . "<BR>";
        }

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

            $this->agent_cost = $this->cost - ($this->cost * ($agentProfit / 100));

            if ($this->test == true) {
                echo 'agentProfit=' . $agentProfit . ' | $this->agent_cost=' . $this->agent_cost . "<BR>";
            }
        }
    }

    public function actionGetBuyingPrice()
    {

        if ($_GET['method'] == 'international') {
            $currency = $this->config['global']['fm_transfer_currency'];
        } else {
            $currency = $this->config['global']['BDService_cambio'];
        }

        if ($_GET['method'] == 'international') {

            $modelSendCreditProducts = SendCreditProducts::model()->findByPk((int) $_GET['id']);

            Yii::app()->session['operatorId'] = $modelSendCreditProducts->operator_id;

            $cost = $modelSendCreditProducts->wholesale_price;
        } else {

            $rateinitial = $this->modelTransferToMobile->transfer_bdservice_rate / 100 + 1;
            //cost to send to provider selected value + admin rate * exchange
            $cost     = $_GET['amountValues'] * $rateinitial * $this->config['global']['BDService_cambio'];
            $product  = 0;
            $currency = '€';
        }

        $methosProfit = 'transfer_' . $_GET['method'] . '_profit';
        $user_profit  = $this->modelTransferToMobile->{$methosProfit};

        $user_cost = $cost - ($cost * ($user_profit / 100));
        echo $currency . ' ' . number_format($user_cost, 2);
    }

    public function confirmRefill()
    {
        /*print_r($_POST['TransferToMobile']);
        exit;*/

        if ($_POST['TransferToMobile']['method'] == 'international') {
            $product = $_POST['TransferToMobile']['amountValues']; //is the amout to refill

            $modelSendCreditRates = SendCreditRates::model()->find(array(
                'condition' => 'id_user = :key',
                'params'    => array(
                    ':key'  => Yii::app()->session['id_user'],
                    ':key1' => $_POST['TransferToMobile']['amountValues'],
                ),
                'with'      => array(
                    'idProduct' => array(
                        'condition' => 'idProduct.id =  :key1',
                    ),
                ),
            ));

            if (!isset($modelSendCreditRates->id)) {

                echo '<div align=center id="container">';
                echo Yii::app()->session['id_user'] . ' ' . $product . ' ' . Yii::app()->session['operatorId'] . "<br>";
                echo '<font color=red>ERROR: 413</font><br><br>';
                echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
                echo '</div>';
                exit;
            }

            $this->sell_price                     = $modelSendCreditRates->sell_price;
            $this->cost                           = $modelSendCreditRates->idProduct->wholesale_price;
            $this->local_currency                 = $modelSendCreditRates->idProduct->currency_dest;
            $this->operator_name                  = $modelSendCreditRates->idProduct->operator_name;
            $this->modelTransferToMobile->product = $product = $modelSendCreditRates->idProduct->product;
        } else {

            $this->cost = $this->actionGetBuyingPriceDBService($_POST['TransferToMobile']['method'], $_POST['TransferToMobile']['amountValuesEUR'], $_POST['TransferToMobile']['amountValuesBDT']);
            $product    = 0;
        }

        $this->calculateCost($product);

        $this->addInDataBase();

        if ($this->test == true) {
            echo "REMOVE " . $this->user_cost . " from user " . $this->modelTransferToMobile->username . "<br>";
        }

        if ($this->modelTransferToMobile->method == 'international') {

            if ($modelSendCreditRates->idProduct->provider == 'Ding') {
                $result = SendCreditDingConnect::sendCredit($_POST['TransferToMobile']['number'], $modelSendCreditRates->idProduct->send_value, $modelSendCreditRates->idProduct->SkuCode, $this->test);
            } else if ($modelSendCreditRates->idProduct->provider == 'Orange2') {
                $result = SendCreditOrange2::sendCredit($_POST['TransferToMobile']['number'], $modelSendCreditRates, $this->test);
            } else {
                $result = $this->sendActionTransferToMobile('topup', $product);
            }
        } else {
            $result = $this->sendActionBDService($this->modelTransferToMobile);
        }

        $this->checkResult($result);

        $this->updateDataBase();
        exit;
    }

    public function checkResult($result)
    {

        if ($this->modelTransferToMobile->method == 'international') {

            $result = explode("error_txt=", $result);

            if (preg_match("/Transaction successful/", $result[1])) {
                $this->releaseCredit($result, '');
            } else {
                echo '<div align=center id="container">';
                echo '<font color=red>ERROR: ' . $result[1] . '</font><br><br>';
                echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
                echo '</div>';
                exit;
            }
        } else {

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
    }

    public function releaseCredit($result, $status)
    {

        if (preg_match('/Orange2/', $result[1])) {
            $result = explode("=", $result[1]);
        }

        if ($this->modelTransferToMobile->method == 'international' && $status != 'error') {
            User::model()->updateByPk(
                Yii::app()->session['id_user'],
                array(
                    'credit' => new CDbExpression('credit - ' . $this->user_cost),
                )
            );
        }

        if ($this->test == true) {
            echo 'cost=' . $this->cost . '. user_cost=' . $this->user_cost . "<br>";
            echo $this->modelTransferToMobile->transfer_show_selling_price . "<br>";
        }

        $argument = $this->modelTransferToMobile->transfer_show_selling_price;
        if ($argument < 10) {
            $fee = '1.0' . $argument;
        } else {
            $fee = '1.' . $argument;
        }

        if ($this->test == true) {
            echo $fee . "<br>";
            echo $this->modelTransferToMobile->transfer_show_selling_price;
        }

        $this->showprice = number_format($this->cost * $fee, 2);

        if ($this->modelTransferToMobile->method == 'international') {

            if ($result[1] == 'Orange2') {

                $description = 'Send Credit ' . $this->local_currency . ' ' . $this->modelTransferToMobile->product . ' - +' . $this->modelTransferToMobile->number . ' via ' . $this->operator_name . ' - EUR ' . $this->sell_price . '. TransactionID =' . $result[2];

                if (isset($_POST['TransferToMobile']['metric']) && strlen($_POST['TransferToMobile']['metric'])) {
                    $description .= ' - Meter ' . $_POST['TransferToMobile']['metric'];
                    # code...
                }
            } else {
                $description = 'Send Credit ' . $this->local_currency . ' ' . $this->modelTransferToMobile->product . ' - +' . $this->modelTransferToMobile->number . ' via ' . $this->operator_name . ' - EUR ' . $this->sell_price;
            }
        } else {
            if ($status == 'error') {
                $description = 'PENDING: ';
            } else {
                $description = '';
            }
            //Send Credit BDT 150 to 01630593593 via flexiload at 2.25"
            $description .= 'Send Credit BDT ' . $_POST['TransferToMobile']['amountValuesBDT'] . ' - ' . $this->modelTransferToMobile->number . ' via ' . $this->modelTransferToMobile->method . ' - EUR ' . $_POST['TransferToMobile']['amountValuesEUR'];
            $this->sell_price = $_POST['TransferToMobile']['amountValuesEUR'];
        }

        if ($this->test == true) {
            echo $description;
        }

        $payment = $this->modelTransferToMobile->method == 'international' ? 1 : 0;
        $values  = ":id_user, :costUser, :description, $payment";
        $field   = 'id_user,credit,description,payment';

        // if ($this->modelTransferToMobile->method != 'international') {
        $values .= "," . $this->send_credit_id;
        $field .= ',invoice_number';
        // }

        $sql     = "INSERT INTO pkg_refill ($field) VALUES ($values)";
        $command = Yii::app()->db->createCommand($sql);
        $command->bindValue(":id_user", Yii::app()->session['id_user'], PDO::PARAM_INT);
        $command->bindValue(":costUser", $this->user_cost * -1, PDO::PARAM_STR);
        $command->bindValue(":description", $description, PDO::PARAM_STR);
        $command->execute();

        $msg = $this->modelTransferToMobile->method == 'international' ? $result[1] : $result;
        if ($result[1] == 'Orange2') {
            $msg = $result[0] . "<br>TransactionID " . $result[2];
        }
        echo '<div align=center id="container">';
        echo '<font color=green>Success: ' . $msg . '</font>' . "<br><br>";
        echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
        echo '<a href="../../index.php/transferToMobile/printRefill?id=' . Yii::app()->db->lastInsertID . '">Print Refill </a>' . "<br><br>";
        echo '</div>';

        if ($this->test == true) {
            echo $sql . "<br>";
        }

        if ($this->modelTransferToMobile->id_user > 1) {
            if ($this->modelTransferToMobile->method == 'international' && $status != 'error') {
                $sql     = "UPDATE  pkg_user SET credit = credit - :costAgent WHERE id = :id";
                $command = Yii::app()->db->createCommand($sql);
                $command->bindValue(":id", $this->modelTransferToMobile->id_user, PDO::PARAM_INT);
                $command->bindValue(":costAgent", $this->agent_cost, PDO::PARAM_STR);
                $command->execute();
            }

            $payment = $this->modelTransferToMobile->method == 'international' ? 1 : 0;
            $values  = ":id_user, :costAgent, :description, $payment";
            $field   = 'id_user,credit,description,payment';

            //if ($modelTransferToMobile->method != 'international') {
            $values .= ",$this->send_credit_id";
            $field .= ',invoice_number';
            //}

            if ($this->test == true) {
                echo 'UPDATE AGENT CREDIT -> ' . $sql . "<br>";
            }

            $sql     = "INSERT INTO pkg_refill ($field) VALUES ($values)";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(":id_user", $this->modelTransferToMobile->id_user, PDO::PARAM_INT);
            $command->bindValue(":costAgent", $this->agent_cost * -1, PDO::PARAM_STR);
            $command->bindValue(":description", $description, PDO::PARAM_STR);
            $command->execute();

            if ($this->test == true) {
                echo 'INSERT AGENT REFILL -> ' . $sql . "<br>";
            }
        }
    }

    public function actionMsisdn_info()
    {

        if (isset($_POST['TransferToMobile']['number'])) {

            $number = $_POST['TransferToMobile']['number'];

            $number = preg_match("/\+/", $number) ? $number : '+' . $number;

            if (isset($this->config['global']['fm_transfer_to_username'])) {

                $modelRefill = Refill::model()->find(
                    'description LIKE :key AND date BETWEEN :key1 AND  NOW() AND payment = 1 AND id_user = :key2',
                    array(
                        ':key'  => "%" . $number . "%",
                        ':key1' => date('Y-m-d H:i', mktime(date('H'), date('i') - 10, date('s'), date('m'), date('d'), date('Y'))),
                        ':key2' => Yii::app()->session['id_user'],
                    )
                );
                if (isset($modelRefill->id)) {
                    echo '<div align=center id="container">';
                    echo "<font color=red>You already send credit to this number. Wait minimal 10 minutes to new recharge</font>";
                    echo '</div>';
                    exit;
                }

                $result = $this->sendActionTransferToMobile('msisdn_info');
                //print_r($result);
                if (preg_match("/Transaction successful/", $result)) {
                    $resultArray = explode("\n", $result);
                    $tmp         = explode('=', $resultArray[3]);
                    $operatorid  = trim(end($tmp));
                } else {
                    //echo "Not foun product from TrasnferRo, try ding";
                    //request provider code to ding and chech if exist products to Ding
                    $operatorid = SendCreditDingConnect::getProviderCode($_POST['TransferToMobile']['number']);
                }
                //find products whit trasnferto operatorid
                $modelSendCreditProducts = SendCreditProducts::model()->findAll('operator_id = :key AND status = 1', array(':key' => $operatorid));

                if (!count($modelSendCreditProducts)) {

                    //not receive Operator ID FROM API. API OFF LINE. GET operator from country_code
                    $numberFormate           = $_POST['TransferToMobile']['number'];
                    $numberFormate           = substr($numberFormate, 0, 2) == '00' ? substr($numberFormate, 2) : $numberFormate;
                    $modelSendCreditProducts = SendCreditProducts::model()->findAll(
                        'country_code = SUBSTRING(:key,1,length(country_code)) AND status = 1',
                        array(
                            ':key' => $numberFormate,
                        )
                    );
                    $forceOperatorSelect = true;
                    // echo $modelSendCreditProducts[0]->id . ' ' . $modelSendCreditProducts[0]->country_code;

                }

                if (!isset($modelSendCreditProducts[0])) {

                    echo '<div align=center id="container">';
                    echo "<font color=red>ERROR. No exist product to this number. Contact admin</font><br><br>";
                    echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
                    echo '</div>';
                    exit;
                }

                $modelSendCreditProducts = SendCreditProducts::model()->findAll(
                    'status = 1 AND operator_name = :key AND country_code =:key1',
                    array(
                        ':key'  => $modelSendCreditProducts[0]->operator_name,
                        ':key1' => $modelSendCreditProducts[0]->country_code,
                    )
                );

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

                if (!count($modelSendCreditRates)) {
                    exit('Before send credit, you need add your sell price');
                }

                $values = array();
                $i      = 0;

                foreach ($modelSendCreditProducts as $key => $product) {

                    if (is_numeric($product->product)) {

                        if ($this->test == true) {
                            echo $product->id . ' -> ' . $product->currency_dest . ' ' . $product->product . ' = ' . $product->currency_orig . ' ' . trim($modelSendCreditRates[$i]->sell_price) . "<BR>";
                        }
                        $values[trim($product->id)]        = '<font size=1px>' . $product->currency_dest . '</font> ' . trim($product->product) . ' = <font size=1px>' . $product->currency_orig . '</font> ' . trim($modelSendCreditRates[$i]->sell_price);
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

                Yii::app()->session['amounts']      = isset($forceOperatorSelect) ? array() : $values;
                Yii::app()->session['operatorId']   = $operatorid;
                Yii::app()->session['ids_products'] = $ids_products;

                $this->modelTransferToMobile->country  = $modelSendCreditProducts[0]->country;
                $this->modelTransferToMobile->operator = isset($forceOperatorSelect) ? '' : $modelSendCreditProducts[0]->operator_name;

                return $this->modelTransferToMobile;
            } else {
                echo 'Service inactive';
            }
        }
    }

    public function actionGetProducts()
    {

        $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
            'condition' => 'operator_name = :key AND status = 1',
            'params'    => array(':key' => $_GET['operator']),
        ));

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

?>
        <?php foreach ($modelSendCreditProducts as $key => $product): ?>
            <label for="2" class="company__row" id="productLabel<?php echo $i ?>">
                <input type="radio" id="productinput<?php echo $i ?>" name="amountValues" value="<?php echo $product->id ?>">
                <div class="company__logo-container" onclick="handleChange1(<?php echo $i ?>,<?php echo count($modelSendCreditProducts) ?>);" id='product<?php echo $i ?>'>
                    <?php echo '<font size=1px>' . $product->currency_dest . ' </font>' . $product->product . ' = <font size=1px>' . $product->currency_orig . ' </font>' . $modelSendCreditRates[$i]->sell_price ?>

                </div>
            </label>
            <?php $i++; ?>
        <?php endforeach; ?>

<?php

        Yii::app()->session['amounts']    = $values;
        Yii::app()->session['operatorId'] = $operatorId;
    }

    public function actionGetBuyingPriceDBService($method = '', $valueAmoutEUR = '', $valueAmoutBDT = '')
    {
        $method    = $method == '' ? $_GET['method'] : $method;
        $methodOld = $method;
        $method    = $method == 'dbbl_rocket' ? 'Rocket' : $method;

        $amountEUR = $valueAmoutEUR == '' ? $_GET['valueAmoutEUR'] : $valueAmoutEUR;
        $amountBDT = $valueAmoutBDT == '' ? $_GET['valueAmoutBDT'] : $valueAmoutBDT;

        $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
            'condition' => 'operator_name = :key AND operator_id = 0',
            'params'    => array(':key' => 'Bangladesh ' . $method),
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

        $amount = $amountEUR - $modelSendCreditRates->sell_price;

        $methosProfit = 'transfer_' . $methodOld . '_profit';

        $user_profit = $this->modelTransferToMobile->{$methosProfit};

        $amountR = $amount - ($amount * ($user_profit / 100));

        //$user_profit = $amount * ($user_profit / 100);

        if (isset($_GET['method'])) {
            echo 'EUR ' . $amountR;
        } else {
            return $amount;
        }
    }

    public function actionConvertCurrency()
    {
        $method = $_GET['method'];
        $method = $method == 'dbbl_rocket' ? 'Rocket' : $method;

        $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
            'condition' => 'operator_name = :key AND operator_id = 0',
            'params'    => array(':key' => 'Bangladesh ' . $method),
        ));

        if ($_GET['currency'] == 'EUR') {

            /*
            Request 2: to Send EUR 2.00, will show Selling price EUR 2.00 and BDT amount converted to BDT 125 to
            send(2.00-0.75/0.01).
            If click on "R", will show EUR 1.25.
             */

            $amountEUR = $_GET['amount'];

            $amountBDT = $amountEUR / ($modelSendCreditProducts[0]->wholesale_price);

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

            echo $amount = number_format(($amountEUR - $modelSendCreditRates->sell_price) / $modelSendCreditProducts[0]->wholesale_price, 0, '', '');
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

            echo $amount = number_format(($amountBDT * $product->wholesale_price) + $modelSendCreditRates->sell_price, 2);
        }
    }

    public function actionGetProductTax()
    {

        $modelSendCreditProducts = SendCreditProducts::model()->findByPk((int) $_GET['id']);
        echo $modelSendCreditProducts->info;
    }
}
