<?php
namespace Applitools;

/**
 * An image provider based on EyesWebDriver.
 */
class TakesScreenshotImageProvider implements ImageProvider {

    /** @var Logger */
    private $logger; //Logger

    /** @var EyesWebDriver */
    private $tsInstance;

    public function __construct(Logger $logger, EyesWebDriver $tsInstance) {
        $this->logger = $logger;
        $this->tsInstance = $tsInstance;
    }

    public function getImage() {
        $this->logger->verbose("Getting screenshot as base64...");
        $screenshot64 = $this->tsInstance->getScreenshotAsBase64();
        $this->logger->verbose("Done getting base64! Creating Image...");
        return $screenshot64;
    }
}
