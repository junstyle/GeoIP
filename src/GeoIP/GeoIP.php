<?php
namespace GeoIP;

class GeoIP
{
	private static $dbFile = __DIR__ . '/../../db/geo.sdb';
	private static $dbSql = __DIR__ . '/../../db/schema.sql';
	private static $dbh;

	/**
	 * lookup
	 * @param string $ipAddress
	 * @return object / false
	 */
	public static function lookup($ipAddress = '', $table = 'country_blocks')
	{
		if (self::isLocal($ipAddress)) {
			return false;
		}

		if (self::$dbh == null) {
			if (!is_file(self::$dbFile)) {
				throw new \Exception('Run init to initialise the database');
			}

			self::$dbh = new \PDO('sqlite:' . self::$dbFile);
			self::$dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		}

		$long = ip2long($ipAddress);

		$sth = self::$dbh->prepare("
			SELECT
				cl.locale_code,
				cl.continent_code,
				cl.continent_name,
				cl.country_iso_code,
				cl.country_name
			FROM  $table         AS cb
			INNER JOIN country_locations AS cl ON cb.geoname_id = cl.geoname_id
			WHERE
				long_start<=:long AND long_end>=:long
			LIMIT 1
			");

		$sth->bindParam('long', $long, \PDO::PARAM_INT);

		$sth->execute();

		$result = $sth->fetchObject();

		if ($result) {
			$result->ip_address = $ipAddress;
		}

		return $result;
	}

	public static function country($ip)
	{
		$result = self::lookup($ip);
		if ($result) {
			return $result->country_name;
		} else {
			return false;
		}
	}

	public static function inChina($ip)
	{
		return !!self::lookup($ip, 'country_blocks_china');
	}

	public static function init($lang = 'zh-CN')
	{
		try {
			$url = 'http://geolite.maxmind.com/download/geoip/database/GeoLite2-Country-CSV.zip';

			$countryBlocks = '';
			$countryLocations = '';

			$files = Importer::getFiles($url, ['GeoLite2-Country-Blocks-IPv4.csv', "GeoLite2-Country-Locations-$lang.csv"]);

			@unlink(self::$dbFile . '.tmp');

			$dbh = new \PDO('sqlite:' . self::$dbFile . '.tmp');

			$dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

			$sql = file_get_contents(self::$dbSql);

			$dbh->exec($sql);

			$dbh->beginTransaction();
			$count = 0;
			Importer::csvRead($files['GeoLite2-Country-Blocks-IPv4.csv'], function ($values) use ($dbh, $count) {
				$cidr = explode('/', $values['network']);

				$longStart = ip2long($cidr[0]) & ((-1 << 32 - $cidr[1]));
				$longEnd = ip2long($cidr[0]) + pow(2, 32 - $cidr[1]) - 1;

				if (empty(trim($values['geoname_id']))) {
					$values['geoname_id'] = trim($values['registered_country_geoname_id']);
				}

				// world
				$sth = $dbh->prepare('
					INSERT INTO country_blocks (
						long_start,
						long_end,
						geoname_id
					) VALUES (
						:long_start,
						:long_end,
						:geoname_id
					)
					');

				// $sth->bindParam('network', $values['network'], \PDO::PARAM_STR);
				$sth->bindParam('long_start', $longStart, \PDO::PARAM_INT);
				$sth->bindParam('long_end', $longEnd, \PDO::PARAM_INT);
				$sth->bindParam('geoname_id', $values['geoname_id'], \PDO::PARAM_INT);
				$sth->execute();

				// china
				if (in_array($values['geoname_id'], ['1814991'])) {
					$sth = $dbh->prepare('
					INSERT INTO country_blocks_china (
						long_start,
						long_end,
						geoname_id
					) VALUES (
						:long_start,
						:long_end,
						:geoname_id
					)
					');

					// $sth->bindParam('network', $values['network'], \PDO::PARAM_STR);
					$sth->bindParam('long_start', $longStart, \PDO::PARAM_INT);
					$sth->bindParam('long_end', $longEnd, \PDO::PARAM_INT);
					$sth->bindParam('geoname_id', $values['geoname_id'], \PDO::PARAM_INT);
					$sth->execute();
				}

				if ($count++ == 5000) {
					$count = 0;
					$dbh->commit();
					$dbh->beginTransaction();
				}
			});
			$dbh->commit();

			// 避免读取中文数据错误，数据分段错误
			setlocale(LC_ALL, str_replace('-', '_', $lang));

			$dbh->beginTransaction();
			Importer::csvRead($files["GeoLite2-Country-Locations-$lang.csv"], function ($values) use ($dbh) {
				$sth = $dbh->prepare('
					INSERT INTO country_locations (
						geoname_id,
						locale_code,
						continent_code,
						continent_name,
						country_iso_code,
						country_name
					) VALUES (
						:geoname_id,
						:locale_code,
						:continent_code,
						:continent_name,
						:country_iso_code,
						:country_name
					)
					');

				$sth->bindParam('geoname_id', $values['geoname_id'], \PDO::PARAM_INT);
				$sth->bindParam('locale_code', $values['locale_code'], \PDO::PARAM_STR);
				$sth->bindParam('continent_code', $values['continent_code'], \PDO::PARAM_STR);
				$sth->bindParam('continent_name', $values['continent_name'], \PDO::PARAM_STR);
				$sth->bindParam('country_iso_code', $values['country_iso_code'], \PDO::PARAM_STR);
				$sth->bindParam('country_name', $values['country_name'], \PDO::PARAM_STR);
				$sth->execute();
			});
			$dbh->commit();
		} catch (\Exception $e) {
			echo $e->getMessage() . "\n";

			exit(1);
		}

		unset($dbh);

		rename(self::$dbFile . '.tmp', self::$dbFile);

		echo "init data successfully.\n";
		exit(0);
	}

	/*
	* PHP 判断是否内网访问
	* @param $ip 待检查的IP
	*/
	public static function isLocal($ip)
	{
		// 127.0.0.0/24, 10.0.0.0/24, 192.168.0.0/16, 172.16.0.0/20
		return preg_match('%^127\.|10\.|192\.168|172\.(1[6-9]|2|3[01])%', $ip);
	}
}
