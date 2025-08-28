<?php

/**
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2023 MagnusSolution. All rights reserved.
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
class BDServiceCommand extends CConsoleCommand
{

    public function run($args)
    {

        define('LOGFILE', 'protected/runtime/BDServicePid.log');
        define('DEBUG', 0);

        if (! defined('PID')) {
            define("PID", "/var/run/magnus/BDServicePid.php");
        }

        if (Process::isActive()) {
            $log = DEBUG >= 1 ? Log::writeLog(LOGFILE, ' line:' . __LINE__ . " PROCESS IS ACTIVE ") : null;
            //die();
        } else {
            Process::activate();
        }
        $log = DEBUG >= 1 ? Log::writeLog(LOGFILE, ' line:' . __LINE__ . " START NOTIFY CLIENT ") : null;

        $this->tanaSend();

        $this->ezzeapi();
    }

    public function tanaSend()
    {

        $config = LoadConfig::getConfig();

        $userBD = $config['global']['BDService_username'];
        $keyBD  = $config['global']['BDService_token'];

        $modelSendCreditSummary = SendCreditSummary::model()->findAll('confirmed = 0 AND service != :key AND date > :key1 AND provider = :key2', [
            ':key'  => 'international',
            ':key1' => date('Y-m-d'),
            ':key2' => 'TanaSend',
        ]);

        foreach ($modelSendCreditSummary as $key => $sendCredit) {
            $url = "http://takasend.org/ezzeapi/status?id=" . $sendCredit->id . "&user=" . $userBD . "&key=" . $keyBD . "";
            if (! $result = @file_get_contents($url, false)) {
                $result = '';
            }
            echo $result . " $sendCredit->id \n";
            $modelRefill = Refill::model()->find(
                'invoice_number = :key AND id_user = :key1',
                [
                    ':key'  => $sendCredit->id,
                    ':key1' => $sendCredit->id_user,
                ]
            );

            if (preg_match("/ERROR|CANCELLED/", strtoupper($result))) {

                $result = explode(':', $result);

                $sendCredit->confirmed = 3;
                $sendCredit->save();

                if (isset($modelRefill->id)) {

                    $modelRefill->description = $modelRefill->description . '. Status: ' . $result[0] . '. Ref:' . $result[1];
                    $modelRefill->payment     = 0;
                    try {
                        $modelRefill->save();
                    } catch (Exception $e) {
                    }

                    $modelUser         = User::model()->findByPk((int) $sendCredit->id_user);
                    $modelUser->credit = $modelUser->credit + ($modelRefill->credit * -1);
                    try {
                        $modelUser->save();
                    } catch (Exception $e) {
                    }

                    if ($modelUser->id_user > 1) {
                        echo "is agent \n";
                        $id_agent         = $modelUser->id_user;
                        $modelRefillAgent = Refill::model()->find(
                            'invoice_number = :key AND id_user = :key1',
                            [
                                ':key'  => $sendCredit->id,
                                ':key1' => $id_agent,
                            ]
                        );

                        if (isset($modelRefillAgent->id)) {
                            $modelRefillAgent->description = $modelRefillAgent->description . '. Status: ' . $result[0] . '. Ref:' . $result[1];
                            $modelRefillAgent->payment     = 0;
                            try {
                                $modelRefillAgent->save();
                            } catch (Exception $e) {
                            }

                            $modelUser         = User::model()->findByPk((int) $id_agent);
                            $modelUser->credit = $modelUser->credit + ($modelRefillAgent->credit * -1);
                            try {
                                $modelUser->save();
                            } catch (Exception $e) {
                            }
                        }
                    }
                }
            } else if (preg_match("/SUCCESS|COMPLETED|ERROR/", $result)) {

                $result = explode(':', $result);

                $sendCredit->confirmed = 1;
                $sendCredit->save();

                if (isset($modelRefill->id)) {

                    $modelRefill->description = @$modelRefill->description . '. Status: ' . $result[0] . '. Ref:' . $result[1];
                    $modelRefill->payment     = 1;
                    try {
                        $modelRefill->save();
                    } catch (Exception $e) {
                    }
                    $modelUser = User::model()->findByPk((int) $sendCredit->id_user);
                    if ($modelUser->id_user > 1) {
                        echo "is agent \n";
                        $id_agent         = $modelUser->id_user;
                        $modelRefillAgent = Refill::model()->find(
                            'invoice_number = :key AND id_user = :key1',
                            [
                                ':key'  => $sendCredit->id,
                                ':key1' => $id_agent,
                            ]
                        );

                        $modelRefillAgent->description = @$modelRefillAgent->description . '. Status: ' . $result[0] . '. Ref:' . $result[1];
                        $modelRefillAgent->payment     = 1;
                        try {
                            $modelRefillAgent->save();
                        } catch (Exception $e) {
                        }
                    }
                }
            }
        }
    }
    public function ezzeapi()
    {

        /*$_POST = array(

        "refid" => 23597,
        'message' => "TakaSend: Amount Of tk.10 SUCCESSFUL ON Mobile No, 01795559444. ID:TX117966843 Today Sale.155.9 ,Your Balance is Now 258.74  [Thankyou]"
        );*/
        $config = LoadConfig::getConfig();

        $userBD        = $config['global']['BDService_username'];
        $keyBD         = $config['global']['BDService_token'];
        $BDService_url = $config['global']['BDService_url'];

        $url = $BDService_url . "/ezzeapi/balance?user=$userBD&key=$keyBD";
        if (! $result = @file_get_contents($url, false)) {
            $result = '';
        }

        Configuration::model()->updateAll(
            ['config_value' => $result],
            'config_key = :key',
            [':key' => 'BDService_credit_provider']
        );

        $modelSendCreditSummary = SendCreditSummary::model()->findAll('confirmed = 0 AND service != :key AND date > :key1 ', [
            ':key'  => 'international',
            ':key1' => date('Y-m-d'),
        ]);

        foreach ($modelSendCreditSummary as $key => $sendCredit) {

            $idApi = $sendCredit->id;

            $url = $BDService_url . "/ezzeapi/status?id=" . $idApi . "&user=" . $userBD . "&key=" . $keyBD;

            if (! $result = @file_get_contents($url, false)) {
                $result = '';
            }

            print_r($result);

            if (preg_match("/SUCCESS/", $result)) {

                $modelRefill = Refill::model()->find(
                    'invoice_number = :key AND id_user = :key1',
                    [
                        ':key'  => $sendCredit->id,
                        ':key1' => $sendCredit->id_user,
                    ]
                );

                if (! isset($modelRefill->id)) {
                    continue;
                }
                $message = explode("SUCCESS: ", $result);
                User::model()->updateByPk(
                    $sendCredit->id_user,
                    [
                        'credit' => new CDbExpression('credit + ' . $modelRefill->credit),
                    ]
                );

                $modelRefill->payment     = 1;
                $modelRefill->description = $modelRefill->description . '. Ref: ' . $message[1];
                $modelRefill->save();

                $sendCredit->confirmed = 1;
                $sendCredit->save();

                if ($sendCredit->idUser->id_user > 1) {

                    echo "\n\nIS A USER AGENT" . $sendCredit->idUser->id_user;

                    $modelRefill = Refill::model()->find(
                        'invoice_number = :key AND id_user = :key1',
                        [
                            ':key'  => $sendCredit->id,
                            ':key1' => $sendCredit->idUser->id_user,
                        ]
                    );

                    User::model()->updateByPk(
                        $sendCredit->idUser->id_user,
                        [
                            'credit' => new CDbExpression('credit + ' . $modelRefill->credit),
                        ]
                    );

                    $modelRefill->payment     = 1;
                    $modelRefill->description = $modelRefill->description . '. Ref: ' . $message[1];
                    $modelRefill->save();
                }
            } else if (preg_match("/ERROR|CANCELLED/", $result)) {

                $sendCredit->confirmed = 3;
                $sendCredit->save();

                $modelRefill = Refill::model()->find('invoice_number = :key', [':key' => $sendCredit->id]);
                if (isset($modelRefill->id)) {
                    $modelRefill->description = $modelRefill->description . '. Ref: ' . $result;
                    $modelRefill->save();
                }
            }
        }
    }
}
