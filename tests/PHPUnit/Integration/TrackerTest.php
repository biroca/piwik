<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Tests\Integration;

use Piwik\Common;
use Piwik\Db;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker;

/**
 * @group Core
 */
class TrackerTest extends IntegrationTestCase
{
    public function setUp()
    {
        parent::setUp();
        Fixture::createWebsite('2014-02-04');
    }

    protected static function configureFixture($fixture)
    {
        $fixture->createSuperUser = true;
    }

    /**
     * Test the Bulk tracking API as documented in: http://developer.piwik.org/api-reference/tracking-api#bulk-tracking
     *
     * With invalid token_auth the request would still work
     */
    public function test_trackingApiWithBulkRequests_viaCurl_withWrongTokenAuth()
    {
        $token_auth = '33dc3f2536d3025974cccb4b4d2d98f4';
        $this->issueBulkTrackingRequest($token_auth, $expectTrackingToSucceed = true);
    }

    public function test_trackingApiWithBulkRequests_viaCurl_withCorrectTokenAuth()
    {
        $token_auth = Fixture::getTokenAuth();
        \Piwik\Filesystem::deleteAllCacheOnUpdate();
        $this->issueBulkTrackingRequest($token_auth, $expectTrackingToSucceed = true);
    }

    public function test_trackingEcommerceOrder_WithHtmlEscapedText_InsertsCorrectLogs()
    {
        // item sku, item name, item category, item price, item quantity
        $ecItems = array(array('&quot;scarysku', 'superscarymovie&quot;', 'scary &amp; movies', 12.99, 1),
                         array('&gt; scary', 'but &lt; &quot;super', 'scary&quot;', 14, 15));

        $urlToTest = "?idsite=1&idgoal=0&rec=1&url=" . urlencode('http://quellehorreur.com/movies') . "&ec_items="
                   . urlencode(json_encode($ecItems)) . '&ec_id=myspecial-id-1234&revenue=16.99&ec_st=12.99&ec_tx=0&ec_sh=3';

        $response = $this->sendTrackingRequestByCurl($urlToTest);
        Fixture::checkResponse($response);

        $this->assertEquals(1, $this->getCountOfConversions());

        $conversionItems = Db::fetchAll("SELECT * FROM " . Common::prefixTable('log_conversion_item'));
        $this->assertEquals(2, count($conversionItems));

        $this->assertActionEquals('&quot;scarysku', $conversionItems[0]['idaction_sku']);
        $this->assertActionEquals('superscarymovie&quot;', $conversionItems[0]['idaction_name']);
        $this->assertActionEquals('scary &amp; movies', $conversionItems[0]['idaction_category']);

        $this->assertActionEquals('&gt; scary', $conversionItems[1]['idaction_sku']);
        $this->assertActionEquals('but &lt; &quot;super', $conversionItems[1]['idaction_name']);
        $this->assertActionEquals('scary&quot;', $conversionItems[1]['idaction_category']);
    }

    public function test_trackingEcommerceOrder_DoesNotFail_WhenEmptyEcommerceItemsParamUsed()
    {
        // item sku, item name, item category, item price, item quantity
        $urlToTest = "?idsite=1&idgoal=0&rec=1&url=" . urlencode('http://quellehorreur.com/movies') . "&ec_items="
                   . '&ec_id=myspecial-id-1234&revenue=16.99&ec_st=12.99&ec_tx=0&ec_sh=3';

        $response = $this->sendTrackingRequestByCurl($urlToTest);
        Fixture::checkResponse($response);

        $this->assertEquals(0, $this->getCountOfConversions());
    }

    public function test_trackingEcommerceOrder_DoesNotFail_WhenNonArrayUsedWithEcommerceItemsParam()
    {
        // item sku, item name, item category, item price, item quantity
        $urlToTest = "?idsite=1&idgoal=0&rec=1&url=" . urlencode('http://quellehorreur.com/movies') . "&ec_items=45"
            . '&ec_id=myspecial-id-1234&revenue=16.99&ec_st=12.99&ec_tx=0&ec_sh=3';

        $response = $this->sendTrackingRequestByCurl($urlToTest);
        Fixture::checkResponse($response);

        $this->assertEquals(0, $this->getCountOfConversions());
    }

    protected function issueBulkTrackingRequest($token_auth, $expectTrackingToSucceed)
    {
        $piwikHost = Fixture::getRootUrl() . 'tests/PHPUnit/proxy/piwik.php';

        $command = 'curl -s -X POST -d \'{"requests":["?idsite=1&url=http://example.org&action_name=Test bulk log Pageview&rec=1","?idsite=1&url=http://example.net/test.htm&action_name=Another bulk page view&rec=1"],"token_auth":"' . $token_auth . '"}\' ' . $piwikHost;

        exec($command, $output, $result);
        if ($result !== 0) {
            throw new \Exception("tracking bulk failed: " . implode("\n", $output) . "\n\ncommand used: $command");
        }
        $output = implode("", $output);
        $this->assertStringStartsWith('{"status":', $output);

        if($expectTrackingToSucceed) {
            $this->assertNotContains('error', $output);
            $this->assertContains('success', $output);
        } else {
            $this->assertContains('error', $output);
            $this->assertNotContains('success', $output);
        }
    }

    private function sendTrackingRequestByCurl($url)
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('Curl is not installed');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, Fixture::getRootUrl() . 'tests/PHPUnit/proxy/piwik.php' . $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $response = substr($response, $headerSize);

        curl_close($ch);

        return $response;
    }

    private function assertActionEquals($expected, $idaction)
    {
        $actionName = Db::fetchOne("SELECT name FROM " . Common::prefixTable('log_action') . " WHERE idaction = ?", array($idaction));
        $this->assertEquals($expected, $actionName);
    }

    private function getCountOfConversions()
    {
        return Db::fetchOne("SELECT COUNT(*) FROM " . Common::prefixTable('log_conversion'));
    }
}