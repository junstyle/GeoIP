<?php
namespace GeoIP;

class GeoIP
{
	private static $dbFile = __DIR__ . '/../../db/geo.sdb';
	private static $dbSql = __DIR__ . '/../../db/schema.sql';

	public static function lookup($ipAddress = '', $dbh = false)
	{
		if ($dbh == false) {
			if (!is_file(self::$dbFile)) {
				throw new Exception('Run init to initialise the database');
			}

			$dbh = new \PDO('sqlite:' . self::$dbFile);
			$dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		}

		$long = ip2long($ipAddress);

		$sth = $dbh->prepare('
			SELECT
				cl.locale_code,
				cl.continent_code,
				cl.continent_name,
				cl.country_iso_code,
				cl.country_name
			FROM  country_blocks         AS cb
			INNER JOIN country_locations AS cl ON cb.geoname_id = cl.geoname_id
			WHERE
				:long BETWEEN long_start AND long_end
			LIMIT 1
			');

		$sth->bindParam('long', $long, \PDO::PARAM_INT);

		$sth->execute();

		$result = $sth->fetchObject();

		$result->ip_address = $ipAddress;

		return $result;
	}

	public static function init($lang = 'zh-CN')
	{
		try {
			$url = 'http://geolite.maxmind.com/download/geoip/database/GeoLite2-Country-CSV.zip';

			@unlink(self::$dbFile);

			$countryBlocks = '';
			$countryLocations = '';

			$files = Importer::getFiles($url, ['GeoLite2-Country-Blocks-IPv4.csv', "GeoLite2-Country-Locations-$lang.csv"]);

			$dbh = new \PDO('sqlite:' . self::$dbFile);

			$dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

			$sql = file_get_contents(self::$dbSql);

			$dbh->exec($sql);

			Importer::csvRead($files['GeoLite2-Country-Blocks-IPv4.csv'], function ($values) use ($dbh) {
				$cidr = explode('/', $values['network']);

				$longStart = ip2long($cidr[0]) & ((-1 << 32 - $cidr[1]));
				$longEnd = ip2long($cidr[0]) + pow(2, 32 - $cidr[1]) - 1;

				$sth = $dbh->prepare('
					INSERT INTO country_blocks (
						network,
						long_start,
						long_end,
						geoname_id,
						registered_country_geoname_id,
						represented_country_geoname_id,
						is_anonymous_proxy,
						is_satellite_provider
					) VALUES (
						:network,
						:long_start,
						:long_end,
						:geoname_id,
						:registered_country_geoname_id,
						:represented_country_geoname_id,
						:is_anonymous_proxy,
						:is_satellite_provider
					)
					');

				$sth->bindParam('network', $values['network'], \PDO::PARAM_STR);
				$sth->bindParam('long_start', $longStart, \PDO::PARAM_INT);
				$sth->bindParam('long_end', $longEnd, \PDO::PARAM_INT);
				$sth->bindParam('geoname_id', $values['geoname_id'], \PDO::PARAM_INT);
				$sth->bindParam('registered_country_geoname_id', $values['registered_country_geoname_id'], \PDO::PARAM_INT);
				$sth->bindParam('represented_country_geoname_id', $values['represented_country_geoname_id'], \PDO::PARAM_INT);
				$sth->bindParam('is_anonymous_proxy', $values['is_anonymous_proxy'], \PDO::PARAM_INT);
				$sth->bindParam('is_satellite_provider', $values['is_satellite_provider'], \PDO::PARAM_INT);

				$sth->execute();
			});

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
		} catch (\Exception $e) {
			echo $e->getMessage() . "\n";

			exit(1);
		}

		echo "init data successfully.\n";
		exit(0);
	}
}
