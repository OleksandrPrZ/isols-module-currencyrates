<?php
declare(strict_types=1);

namespace Isols\CurrencyRates\Model\Currency\Import;

use Exception;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\ResourceModel\Currency as CurrencyResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\LaminasClientFactory as HttpClientFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Directory\Model\Currency\Import\ImportInterface;

class CzechCentralBank implements ImportInterface
{
    private const RATES_URL = 'currency/czech_central_bank/currency_rates_url';
    /**
     * @var array
     */
    private array $messages = [];

    /**
     * @var HttpClientFactory
     */
    private HttpClientFactory $httpClientFactory;

    /**
     * @var CurrencyFactory
     */
    private CurrencyFactory $currencyFactory;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * Constructor to initialize dependencies
     *
     * @param CurrencyFactory $currencyFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param HttpClientFactory $httpClientFactory
     */
    public function __construct(
        CurrencyFactory      $currencyFactory,
        ScopeConfigInterface $scopeConfig,
        HttpClientFactory    $httpClientFactory
    )
    {
        $this->currencyFactory = $currencyFactory;
        $this->scopeConfig = $scopeConfig;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * Import the currency rates
     *
     * @return $this
     * @throws Exception
     */
    public function importRates(): self
    {
        $data = $this->fetchRates();
        $this->saveRates($data);

        return $this;
    }

    /**
     * Fetch the currency rates
     *
     * @return array
     */
    public function fetchRates(): array
    {
        return $this->getCurrencyRates();
    }

    /**
     * Get the messages related to currency rates import
     *
     * @return array
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Fetch and parse the currency rates from the external service
     *
     * @return array
     */
    private function getCurrencyRates(): array
    {
        try {
            $url = $this->scopeConfig->getValue(self::RATES_URL, ScopeInterface::SCOPE_STORE);
            if (!$url) {
                throw new Exception('Currency Rates URL is not configured.');
            }
            $response = $this->getServiceResponse($url);

            return $this->parseRates($response);

        } catch (Exception $e) {
            $this->messages[] = __('Error fetching currency rates: ') . $e->getMessage();

            return [];
        }
    }

    /**
     * Send an HTTP GET request to the provided URL and get the response
     *
     * @param string $url
     * @return string
     */
    private function getServiceResponse(string $url): string
    {
        $httpClient = $this->httpClientFactory->create();
        $response = '';

        try {
            $httpClient->setUri($url);
            $httpClient->setMethod('GET');
            $response = $httpClient->send()->getBody();
        } catch (Exception $e) {
            $this->messages[] = __('Error during HTTP request: ') . $e->getMessage();
        }

        return $response;
    }

    /**
     * Parse the response and extract the currency rates
     *
     * @param string $response
     * @return array
     */
    private function parseRates(string $response): array
    {
        $data = [];
        $lines = explode("\n", $response);

        foreach ($lines as $line) {
            if ($this->isHeaderOrEmptyLine($line)) {
                continue;
            }
            $fields = $this->parseLine($line);
            if ($fields) {
                $currencyCode = $fields['currency_code'];
                $rate = $fields['rate'];
                $quantity = $fields['quantity'];

                if ($quantity > 1) {
                    $rate /= $quantity;
                }
                $data['CZK'][$currencyCode] = 1 / $rate;
            }
        }

        return $data;
    }


    /**
     * Check if the line is a header or an empty line
     *
     * @param string $line
     * @return bool
     */
    private function isHeaderOrEmptyLine(string $line): bool
    {
        return empty($line) ||
            preg_match('/země\|měna\|množství\|kód\|kurz/', trim($line)) ||
            !str_contains($line, '|');
    }

    /**
     * Parse a line of data to extract the currency code and rate
     *
     * @param string $line
     * @return array|null
     */
    private function parseLine(string $line): ?array
    {
        $fields = explode('|', $line);

        if (count($fields) < 5) {
            return null;
        }

        $currencyCode = trim($fields[3]);
        $quantity = trim($fields[2]);
        $rate = trim($fields[4]);
        $rate = str_replace(',', '.', $rate);

        return [
            'currency_code' => $currencyCode,
            'quantity' => $quantity,
            'rate' => (float)$rate
        ];
    }

    /**
     * Save the currency rates to the Magento system
     *
     * @param array $rates
     * @return void
     * @throws Exception
     */
    private function saveRates(array $rates): void
    {
        if (empty($rates)) {
            $this->messages[] = __('No currency rates to save.');
            return;
        }

        /** @var CurrencyResource $currencyResource */
        $currencyResource = $this->currencyFactory->create()->getResource();

        try {
            $currencyResource->saveRates($rates);
            $this->messages[] = __('Currency rates successfully saved.');
        } catch (Exception $e) {
            $this->messages[] = __('Error saving currency rates: ') . $e->getMessage();
        }
    }
}
