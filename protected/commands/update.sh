#!/bin/bash
clear
echo
echo
echo
echo "=======================WWW.MAGNUSBILLING.COM===========================";
echo "_      _                               ______ _ _ _ _  			     ";
echo "|\    /|                               | ___ (_) | (_) 			     ";
echo "| \  / | ___  ____ _ __  _   _   _____ | |_/ /_| | |_ _ __   __ _ 	 ";
echo "|  \/  |/   \/  _ \| '_ \| | | \| ___| | ___ \ | | | | '_ \ /  _ \	 ";
echo "| |\/| |  | |  (_| | | | | |_| ||____  | |_/ / | | | | | | |  (_| |	 ";
echo "|_|  |_|\___|\___  |_| | |_____|_____|  \___/|_|_|_|_|_| |_|\___  |	 ";
echo "                _/ |                                           _/ |	 ";
echo "               |__/                                           |__/ 	 ";
echo "														                 ";
echo "============================== UPDATE =================================";
echo

sleep 2

if [[ -e /var/www/html/mbilling/protected/commands/update2.sh ]]; then
	/var/www/html/mbilling/protected/commands/update2.sh
	exit;
fi


get_linux_distribution ()
{ 
    if [ -f /etc/debian_version ]; then
        DIST="DEBIAN"
        HTTP_DIR="/etc/apache2/"
        HTTP_CONFIG=${HTTP_DIR}"apache2.conf"
        MYSQL_CONFIG="/etc/mysql/mariadb.conf.d/50-server.cnf"
        SERVICE='apache2'
    elif [ -f /etc/redhat-release ]; then
        DIST="CENTOS"
        HTTP_DIR="/etc/httpd/"
        HTTP_CONFIG=${HTTP_DIR}"conf/httpd.conf"
        MYSQL_CONFIG="/etc/my.cnf"
         SERVICE='httpd'
    else
        DIST="OTHER"
        echo 'Installation does not support your distribution'
        exit 1
    fi
}



get_linux_distribution


cd /var/www/html/mbilling
rm -rf MagnusBilling-current.tar.gz
wget --no-check-certificate https://magnusbilling.org/download/MagnusBilling-current.tar.gz
tar xzf MagnusBilling-current.tar.gz


## remove unnecessary directories
rm -rf /var/www/html/mbilling/doc
rm -rf /var/www/html/mbilling/script
rm -rf /var/www/html/mbilling/assets/*
## set default permissions 
touch /etc/asterisk/extensions_magnus_did.conf
chown -R asterisk:asterisk /var/lib/php/session*
chown -R asterisk:asterisk /var/spool/asterisk/outgoing/
chown -R asterisk:asterisk /etc/asterisk
chown -R asterisk:asterisk /var/www/html/mbilling
chown -R asterisk:asterisk /var/lib/asterisk/moh/
chown -R asterisk:asterisk /var/lib/asterisk/sounds/
mkdir -p /usr/local/src/magnus
rm -rf /var/www/html/mbilling/tmp
mkdir -p /var/www/html/mbilling/tmp
chown -R asterisk:asterisk /var/www/html/mbilling/tmp
chown -R asterisk:asterisk /var/run/magnus/
chown -R root:root /root
chown -R mysql:mysql /var/lib/mysql
chmod -R 777 /tmp
chmod -R 755 /usr/local/src/magnus
chmod -R 555 /var/www/html/mbilling/
chmod -R 700 /var/www/html/mbilling/resources/reports 
chmod -R 774 /var/www/html/mbilling/protected/runtime/
chmod 774 /var/www/html/mbilling/resources/ip.blacklist
chmod -R 755 /var/www/html/mbilling/tmp
chmod -R 700 /var/www/html/mbilling/assets
chmod -R 700 /var/www/html/mbilling/resources/images
echo 'Options -Indexes
Order Deny,Allow
Deny from all
' > /var/www/html/mbilling/assets/.htaccess
echo 'Options -Indexes
Order Deny,Allow
Deny from all
' > /var/www/html/mbilling/lib/.htaccess
chmod +x /var/www/html/mbilling/resources/asterisk/mbilling.php
sed -i "s/AllowOverride None/AllowOverride All/" ${HTTP_CONFIG}
systemctl reload ${SERVICE}
/var/www/html/mbilling/protected/commands/clear_memory
if [[ -e /var/www/html/mbilling/resources/images/lock-screen-background.jpg ]]; then
	for color in black blue gray orange purple red yellow green
	do
		for type in crisp neptune triton
		do
			chmod -R 755 /var/www/html/mbilling/$color-$type/resources/images/
			yes | cp -rf /var/www/html/mbilling/resources/images/lock-screen-background.jpg /var/www/html/mbilling/$color-$type/resources/images/
		done	
	done	
fi

##update database
php /var/www/html/mbilling/cron.php UpdateMysql

if [[ -e /var/www/html/mbilling/protected/commands/update3.sh ]]; then
	/var/www/html/mbilling/protected/commands/update3.sh
fi

