<?php

namespace Applitools;

use Applitools\Exceptions\EyesException;
use Applitools\Exceptions\TestFailedException;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriver;
use Facebook\WebDriver\WebDriverAction;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;

/**
 * The main API gateway for the SDK.
 */
class Eyes extends EyesBase
{

    /*public interface WebDriverAction {
    void drive(WebDriver driver); //FIXME
    }*/

    const UNKNOWN_DEVICE_PIXEL_RATIO = 0;
    const DEFAULT_DEVICE_PIXEL_RATIO = 1;


    const USE_DEFAULT_MATCH_TIMEOUT = -1;

    // Seconds
    const RESPONSE_TIME_DEFAULT_DEADLINE = 10;
    // Seconds
    const RESPONSE_TIME_DEFAULT_DIFF_FROM_DEADLINE = 20;

    // Microseconds
    const DEFAULT_WAIT_BEFORE_SCREENSHOTS = 100000;

    /** @var EyesWebDriver */
    protected $driver;
    private $doNotGetTitle;

    private $forceFullPageScreenshot;
    private $checkFrameOrElement;

    /** @var RegionProvider */
    private $regionToCheck;

    private $hideScrollbars;
    private $rotation; //ImageRotation
    private $devicePixelRatio;
    private $stitchMode; //StitchMode
    private $waitBeforeScreenshots;
    private $regionVisibilityStrategy; //RegionVisibilityStrategy

    /**
     * Creates a new (possibly disabled) Eyes instance that interacts with the
     * Eyes Server at the specified url.
     *
     * @param string $serverUrl The Eyes server URL.
     */
    public function __construct($serverUrl = null)
    {
        if (empty($serverUrl)) {
            $serverUrl = $this->getDefaultServerUrl();
        }

        parent::__construct($serverUrl);

        $this->checkFrameOrElement = false;
        $this->regionToCheck = null;
        $this->forceFullPageScreenshot = false;
        $this->doNotGetTitle = false;
        $this->hideScrollbars = false;
        $this->devicePixelRatio = self::UNKNOWN_DEVICE_PIXEL_RATIO;
        $this->stitchMode = StitchMode::SCROLL;
        $this->rotation = new ImageRotation(0);
        $this->waitBeforeScreenshots = self::DEFAULT_WAIT_BEFORE_SCREENSHOTS;
        $this->regionVisibilityStrategy = new MoveToRegionVisibilityStrategy($this->logger);
    }


    public function getBaseAgentId()
    {
        return "eyes.selenium.php/0.1";
    }

    /**
     * ﻿Forces a full page screenshot (by scrolling and stitching) if the
     * browser only ﻿supports viewport screenshots).
     *
     * @param $shouldForce bool Whether to force a full page screenshot or not.
     */
    public function setForceFullPageScreenshot($shouldForce)
    {
        $this->forceFullPageScreenshot = $shouldForce;
    }

    /**
     * @return bool Whether Eyes should force a full page screenshot.
     */
    public function getForceFullPageScreenshot()
    {
        return $this->forceFullPageScreenshot;
    }

    /**
     * Sets the time to wait just before taking a screenshot (e.g., to allow
     * positioning to stabilize when performing a full page stitching).
     *
     * @param int $waitBeforeScreenshots The time to wait (Milliseconds). Values
     *                              smaller or equal to 0, will cause the
     *                              default value to be used.
     */
    public function setWaitBeforeScreenshots($waitBeforeScreenshots)
    {
        if ($waitBeforeScreenshots <= 0) {
            $this->waitBeforeScreenshots = self::DEFAULT_WAIT_BEFORE_SCREENSHOTS;
        } else {
            $this->waitBeforeScreenshots = $waitBeforeScreenshots;
        }
    }

    /**
     *
     * @return int The time to wait just before taking a screenshot.
     */
    public function getWaitBeforeScreenshots()
    {
        return $this->waitBeforeScreenshots;
    }

    /**
     * Turns on/off the automatic scrolling to a region being checked by
     * {@code checkRegion}.
     *
     * @param bool $shouldScroll Whether to automatically scroll to a region being validated.
     */
    public function setScrollToRegion($shouldScroll)
    {
        if ($shouldScroll) {
            $this->regionVisibilityStrategy =
                new MoveToRegionVisibilityStrategy($this->logger);
        } else {
            $this->regionVisibilityStrategy = new NopRegionVisibilityStrategy($this->logger);
        }
    }

    /**
     * @return bool Whether to automatically scroll to a region being validated.
     */
    public function getScrollToRegion()
    {
        return !($this->regionVisibilityStrategy instanceof NopRegionVisibilityStrategy);
    }

    /**
     * Set the type of stitching used for full page screenshots. When the
     * page includes fixed position header/sidebar, use {@link StitchMode#CSS}.
     * Default is {@link StitchMode#SCROLL}.
     *
     * @param $mode string The stitch mode to set.
     */
    public function setStitchMode($mode)
    {
        $this->stitchMode = $mode;
        if ($this->driver != null) {
            switch ($mode) {
                case StitchMode::CSS:
                    $posProvider = new CssTranslatePositionProvider($this->logger, $this->driver);
                    $this->setPositionProvider($posProvider);
                    break;
                default:
                    $posProvider = new ScrollPositionProvider($this->logger, $this->driver);
                    $this->setPositionProvider($posProvider);
            }
        }
    }

    /**
     *
     * @return string The current stitch mode settings.
     */
    public function getStitchMode()
    {
        return $this->stitchMode;
    }

    /**
     * Hide the scrollbars when taking screenshots.
     * @param bool $shouldHide Whether to hide the scrollbars or not.
     */
    public function setHideScrollbars($shouldHide)
    {
        $this->hideScrollbars = $shouldHide;
    }

    /**
     *
     * @return bool Whether or not scrollbars are hidden when taking screenshots.
     */
    public function getHideScrollbars()
    {
        return $this->hideScrollbars;
    }

    /**
     *
     * @return ImageRotation The image rotation data.
     */
    public function getRotation()
    {
        return $this->rotation;
    }

    /**
     *
     * @param ImageRotation $rotation The image rotation data.
     */
    public function setRotation(ImageRotation $rotation)
    {
        $this->rotation = $rotation;
        if ($this->driver != null) {
            $this->driver->setRotation($rotation);
        }
    }

    /**
     *
     * @return float The device pixel ratio, or {@link #UNKNOWN_DEVICE_PIXEL_RATIO}
     * if the DPR is not known yet or if it was not possible to extract it.
     */
    public function getDevicePixelRatio()
    {
        return $this->devicePixelRatio;
    }

    /**
     * Starts a test.
     *
     * @param $driver WebDriver            The web driver that controls the browser hosting
     *                                     the application under test.
     * @param $appName string              The name of the application under test.
     * @param $testName string             The test name.
     * @param $viewportSize RectangleSize  The required browser's viewport size
     *                                     (i.e., the visible part of the document's body) or
     *                                     {@code null} to use the current window's viewport.
     * @param $sessionType string          The type of test (e.g.,  standard test / visual performance test).
     * @return EyesWebDriver|WebDriver     A wrapped WebDriver which enables Eyes trigger recording and frame handling.
     * @throws EyesException
     */
    public function open(WebDriver $driver, $appName, $testName, RectangleSize $viewportSize = null, $sessionType = null)
    {
        if ($this->getIsDisabled()) {
            $this->logger->verbose("Ignored");
            return $driver;
        }
        if (empty($viewportSize)) {
            if (empty($driver)) {
                //FIXME need to extract  EyesSeleniumUtils::extractViewportSize
            } else { //FIXME need to optimize code
                $viewportSize = new RectangleSize(
                    $driver->manage()->window()->getSize()->getWidth(),
                    $driver->manage()->window()->getSize()->getHeight()
                );
            }
        }

        $this->driver = $driver; // temporarily, so setViewportSize will work.

        $this->openBase($appName, $testName, $viewportSize, $sessionType);

        ArgumentGuard::notNull($driver, "driver");

        if ($driver instanceof RemoteWebDriver) {
            $this->driver = new EyesWebDriver($this->logger, $this, $driver);
        } else if ($driver instanceof EyesWebDriver) {
            $this->driver = $driver;
        } else {
            $errMsg = "Driver is not a RemoteWebDriver (" . get_class($driver) . ")";
            $this->logger->log($errMsg);
            throw new EyesException($errMsg);
        }
        $this->devicePixelRatio = self::UNKNOWN_DEVICE_PIXEL_RATIO;

        // Setting the correct position provider.
        switch ($this->getStitchMode()) {
            case StitchMode::CSS:
                $cssTranslatePositionNew = new CssTranslatePositionProvider($this->logger, $this->driver);
                $this->setPositionProvider($cssTranslatePositionNew);
                break;
            default:
                $scrollPositionNew = new ScrollPositionProvider($this->logger, $this->driver);
                $this->setPositionProvider($scrollPositionNew);
        }
        $this->driver->setRotation($this->rotation);

        return $this->driver;
    }


    /**
     * Takes a snapshot of the application under test and matches it with the expected output.
     *
     * @param int $matchTimeout The amount of time to retry matching (Milliseconds).
     * @param string $tag An optional tag to be associated with the snapshot.
     * @throws TestFailedException Thrown if a mismatch is detected and immediate failure reports are enabled.
     */
    public function checkWindow($tag, $matchTimeout = null)
    {
        if (empty($matchTimeout)) {
            $matchTimeout = self::USE_DEFAULT_MATCH_TIMEOUT;
        }

        if ($this->getIsDisabled()) {
            $this->logger->log(sprintf("CheckWindow(%d, '%s'): Ignored", $matchTimeout, $tag));
            return;
        }
        $this->logger->log(sprintf("CheckWindow(%d, '%s')", $matchTimeout, $tag));
        $regionProvider = new RegionProvider();
        parent::checkWindowBase($regionProvider, $tag, false, $matchTimeout);
    }

    /**
     * Runs a test on the current window.
     *
     * @param $driver WebDriver            The web driver that controls the browser hosting the application under test.
     * @param $appName string              The name of the application under test.
     * @param $testName string             The test name (will also be used as the tag name for the step).
     * @param $viewportSize RectangleSize  The required browser's viewport size
     *                                    (i.e., the visible part of the document's body) or
     *                                    {@code null} to use the current window's viewport.
     */
    public function testWindow(WebDriver $driver, $appName = null, $testName, RectangleSize $viewportSize = null)
    {
        $this->open($driver, $appName, $testName, $viewportSize);
        try {
            $this->checkWindow($testName);
            $this->close();
        } finally {
            $this->abortIfNotClosed();
        }
    }

    /**
     * Run a visual performance test.
     * @param $driver WebDriver The driver to use.
     * @param $appName string The name of the application being tested.
     * @param $testName string The test name.
     * @param $action WebDriverAction Action to be performed in parallel to starting the test.
     * @param $deadline int The expected time until the application should have been loaded. (Seconds)
     * @param $timeout int The maximum time until the application should have been loaded. (Seconds)
     * @param RectangleSize $viewportSize
     */
    public function testResponseTime(WebDriver $driver, $appName,
                                     $testName, WebDriverAction $action = null,
                                     $deadline, $timeout, RectangleSize $viewportSize)
    {
        if (empty($deadline)) {
            $deadline = self::RESPONSE_TIME_DEFAULT_DEADLINE;
        }
        if (empty($timeout)) {
            $timeout = $deadline + self::RESPONSE_TIME_DEFAULT_DIFF_FROM_DEADLINE;
        }
        if (!empty($viewportSize)) {
            $this->setViewportSize($viewportSize, $driver);
        }
        $this->open($driver, $appName, $testName, SessionType::PROGRESSION);
        $runnableAction = null;
        if ($action != null) {
            $runnableAction = null;/*new Runnable() {
                public void run() {
                action.drive(driver);
                }
            };*/
        }
        $regionProvider = new RegionProvider();
        $result = parent::testResponseTimeBase($regionProvider, $runnableAction, $deadline, $timeout, 5000);

        $this->logger->log("Checking if deadline was exceeded...");
        $deadlineExceeded = true;
        if ($result != null) {
            $tao = /*TimedAppOutput*/
                $result->getMatchWindowData()->getAppOutput();
            $resultElapsed = $tao->getElapsed();
            $deadlineMs = $deadline * 1000;
            $this->logger->log(sprintf("Deadline: %d, Elapsed time for match: %d", $deadlineMs, $resultElapsed));
            $deadlineExceeded = $resultElapsed > $deadlineMs;
        }
        $this->logger->log("Deadline exceeded? $deadlineExceeded");

        $this->closeResponseTime($deadlineExceeded);
    }

    /**
     * Takes a snapshot of the application under test and matches a specific
     * region within it with the expected output.
     *
     * @param mixed $region A non empty region representing the screen region to check.
     * @param int $matchTimeout The amount of time to retry matching. (Milliseconds)
     * @param string $tag An optional tag to be associated with the snapshot.
     * @param bool $stitchContent
     * @throws \Exception
     */
    public function checkRegion($region, $matchTimeout = null, $tag = null, $stitchContent = false)
    {
        if ($this->getIsDisabled()) {
            $this->logger->log("CheckRegion($region, $matchTimeout, '$tag'): Ignored");
            return;
        }

        $targetRegion = null;
        if ($region instanceof Region) {
            $targetRegion = $region;
        } else if ($region instanceof WebDriverElement) {
            $this->checkRegionByElement($region, $matchTimeout, $tag, $stitchContent);
            return;
        } else if ($region instanceof WebDriverBy) {
            $element = $this->driver->findElement($region);
            $this->checkRegionByElement($element, $matchTimeout, $tag, $stitchContent);
            return;
        } else if (is_string($region)) {
            $element = self::findElement($this->driver, $region);
            $this->checkRegionByElement($element, $matchTimeout, $tag, $stitchContent);
            return;
        } else {
            throw new \Exception("couldn't handle region of type " . get_class($region));
        }

        ArgumentGuard::notNull($targetRegion, "region");

        $this->logger->log("CheckRegion($targetRegion, $matchTimeout, '$tag')");

        $regionProvider = new RegionProvider($targetRegion);
        $regionProvider->setCoordinatesType(CoordinatesType::SCREENSHOT_AS_IS); //FIXME need to check
        parent::checkWindowBase(
            $regionProvider,
            $tag,
            false,
            $matchTimeout
        );
        $this->logger->log("Done! trying to scroll back to original position...");
        $this->regionVisibilityStrategy->returnToOriginalPosition($this->positionProvider); /// ????
        $this->logger->log("Done!");
    }

    /**
     * Takes a snapshot of the application under test and matches a region
     * specified by the given selector with the expected region output.
     *
     * @param WebDriverBy $selector Selects the region to check.
     * @param int $matchTimeout The amount of time to retry matching. (Milliseconds)
     * @param string $tag An optional tag to be associated with the screenshot.
     * @param  bool $stitchContent
     * @throws TestFailedException if a mismatch is detected and immediate failure reports are enabled
     */
    public function checkRegionBySelector(WebDriverBy $selector, $matchTimeout = null, $tag, $stitchContent = false)
    {
        if ($this->getIsDisabled()) {
            $this->logger->log("checkRegionBySelector(selector, $matchTimeout, '$tag'): Ignored");
            return;
        }

        $element = $this->driver->findElement($selector);
        $this->checkRegionByElement($element, $matchTimeout, $tag, $stitchContent);
    }

    /**
     * Takes a snapshot of the application under test and matches a region of
     * a specific element with the expected region output.
     *
     * @param WebDriverElement $element The element which represents the region to check.
     * @param int $matchTimeout The amount of time to retry matching. (Milliseconds)
     * @param string $tag An optional tag to be associated with the snapshot.
     * @param bool $stitchContent
     */
    public function checkRegionByElement(WebDriverElement $element, $matchTimeout = -1, $tag, $stitchContent = false)
    {
        if ($this->getIsDisabled()) {
            $this->logger->log("checkRegionByElement(element, $matchTimeout, '$tag'): Ignored");
            return;
        }

        ArgumentGuard::notNull($element, "element");

        $this->logger->verbose("checkRegionByElement(element, $matchTimeout, '$tag', $stitchContent)");

        if ($stitchContent) {
            $this->checkElement($element, $matchTimeout, $tag);
            return;
        }

        // If needed, scroll to the top/left of the element (additional help to make sure it's visible).
        $locationAsPoint = $element->getLocation();
        $this->regionVisibilityStrategy->moveToRegion($this->positionProvider,
            new Location($locationAsPoint->getX(), $locationAsPoint->getY()));

        $eyesElement = new EyesRemoteWebElement($this->logger, $this->driver, $element);
        $fullRegion = new FullRegionProvider($eyesElement);
        parent::checkWindowBase(
            $fullRegion,
            $tag,
            false,
            $matchTimeout
        );
        $this->logger->verbose("Done! trying to scroll back to original position..");
        $this->regionVisibilityStrategy->returnToOriginalPosition($this->positionProvider);
        $this->logger->verbose("Done!");
    }

    private function findElementMixed($element)
    {
        $targetElement = null;
        if ($element instanceof WebDriverElement) {
            $targetElement = $element;
        } else if ($element instanceof WebDriverBy) {
            $targetElement = $this->driver->findElement($element);
        } else if (is_string($element)) {
            $targetElement = self::findElement($this->driver, $element);
        } else if ($element instanceof Frame) {
            $targetElement = $element->getReference();
        } else {
            throw new \Exception("couldn't find target element");
        }

        return $targetElement;
    }

    /**
     * Switches into the given frame, takes a snapshot of the application under
     * test and matches a region specified by the given selector.
     *
     * @param mixed $frame A selector by which to find a frame.
     * @param mixed $element A selector specifying the region to check.
     * @param string $tag An optional tag to be associated with the snapshot.
     * @param bool $stitchContent If {@code true}, stitch the internal content of the region (i.e., perform {@link #checkElement(By, int, String)} on the region.
     * @param int $matchTimeout The amount of time to retry matching. (Milliseconds)
     */
    public function checkRegionInFrame($frame, $element, $tag = null, $stitchContent = false, $matchTimeout = null)
    {
        if ($this->getIsDisabled()) {
            $this->logger->log("checkRegionInFrame($frame, $element, $matchTimeout, '$tag', $stitchContent): Ignored");
            return;
        }
        if (empty($matchTimeout)) {
            $matchTimeout = self::USE_DEFAULT_MATCH_TIMEOUT;
        }

        $targetFrame = $this->findElementMixed($frame);

        $this->logger->log("Switching to frame with name/id: '{$targetFrame->getID()}' ...");

        $locationAsPoint = $targetFrame->getLocation();
        $this->regionVisibilityStrategy->moveToRegion($this->getPositionProvider(), new Location($locationAsPoint->getX(), $locationAsPoint->getY()));

        $this->driver->switchTo()->frame($targetFrame);

        $targetElement = $this->findElementMixed($element);

        if ($stitchContent) {
            $this->checkElement($targetElement, $matchTimeout, $tag);
        } else {
            $this->checkRegionByElement($this->driver->findElement($targetElement), $matchTimeout, $tag);
        }

        $this->logger->log("Switching back to parent frame");
        $this->driver->switchTo()->parentFrame();

        $this->regionVisibilityStrategy->returnToOriginalPosition($this->getPositionProvider());
        $this->logger->log("Done!");
    }

    /**
     * Updates the state of scaling related parameters.
     */
    protected function updateScalingParams()
    {
        if ($this->devicePixelRatio == self::UNKNOWN_DEVICE_PIXEL_RATIO) {
            $this->logger->log("Trying to extract device pixel ratio...");
            try {
                $this->devicePixelRatio = EyesSeleniumUtils::getDevicePixelRatio($this->driver);
            } catch (\Exception $e) {
                $this->logger->log("Failed to extract device pixel ratio! Using default.");
                $this->devicePixelRatio = self::DEFAULT_DEVICE_PIXEL_RATIO;
            }
            $this->logger->log("Device pixel ratio: {$this->devicePixelRatio}");

            $this->logger->log("Setting scale provider..");
            try {
                $factory = new ContextBasedScaleProviderFactory($this->positionProvider->getEntireSize(), $this->getViewportSize(),
                    $this->getScaleMethod(), $this->devicePixelRatio, $this->scaleProviderHandler);
                /*$this->scaleProviderHandler->set(new ContextBasedScaleProvider(
                    $this->positionProvider->getEntireSize(), $this->getViewportSize(),
                    $this->getScaleMethod(), $this->devicePixelRatio));*/
            } catch (\Exception $e) {
                // This can happen in Appium for example.
                $this->logger->log("Failed to set ContextBasedScaleProvider.");
                $this->logger->log("Using FixedScaleProvider instead...");
                /*$this->scaleProviderHandler->set(new FixedScaleProvider(1 / $this->devicePixelRatio));*/
                $factory = new FixedScaleProviderFactory(1 / $this->devicePixelRatio, $this->getScaleMethod(), $this->scaleProviderHandler);
            }
            $this->logger->log("Done!");
            return $factory;
        }
        // If we already have a scale provider set, we'll just use it, and pass a mock as provider handler.
        $nullProvider = new SimplePropertyHandler();

        return new ScaleProviderIdentityFactory($this->scaleProviderHandler->get(), $nullProvider);
    }

    /**
     * Verifies the current frame.
     *
     * @param int $matchTimeout The amount of time to retry matching. Milliseconds)
     * @param string $tag An optional tag to be associated with the snapshot.
     */
    protected function checkCurrentFrame($matchTimeout, $tag)
    {
        try {
            $this->logger->log(sprintf("CheckCurrentFrame(%d, '%s')", $matchTimeout, $tag));

            $this->checkFrameOrElement = true;

            $this->logger->log("Getting screenshot as base64..");
            $screenshotImage = $this->driver->getScreenshotAsBase64();

            $this->logger->log("Done! Creating image object...");

            $scaleProvider = $this->updateScalingParams()->getScaleProvider($screenshotImage->width());

            $screenshotImage = ImageUtils::scaleImage($screenshotImage, $scaleProvider->getScaleRatio());

            $this->logger->log("Done! Building required object...");

            $screenshot = new EyesWebDriverScreenshot($this->logger, $this->driver, $screenshotImage);

            $this->logger->log("Done!");

            $this->regionToCheck = new RegionProvider($screenshot->getFrameWindow());

            $this->regionToCheck->setCoordinatesType(CoordinatesType::SCREENSHOT_AS_IS);

            parent::checkWindowBase($this->regionToCheck, $tag, false, $matchTimeout);
        } finally {
            $this->checkFrameOrElement = false;
            $this->regionToCheck = null;
        }
    }

    /**
     * Matches the frame given as parameter, by switching into the frame and
     * using stitching to get an image of the frame.
     *
     * @param $frameNameOrId string The name or id of the frame to check. (The same name/id as would be used in a call to driver.switchTo().frame()).
     * @param $matchTimeout int The amount of time to retry matching. (Milliseconds)
     * @param $tag string An optional tag to be associated with the match.
     */
    public function checkFrame($frameNameOrId, $matchTimeout, $tag)
    {
        if ($this->getIsDisabled()) {
            $this->logger->log("checkFrame('$frameNameOrId', $matchTimeout, '$tag'): Ignored");
            return;
        }
        if (empty($matchTimeout)) {
            $matchTimeout = self::USE_DEFAULT_MATCH_TIMEOUT;
        }

        ArgumentGuard::notNull($frameNameOrId, "frameNameOrId");

        $this->logger->log("checkFrame('$frameNameOrId', $matchTimeout, '$tag')");
        $this->logger->log("Switching to frame with name/id: '$frameNameOrId' ...");

        $locationAsPoint = self::findElement($this->driver, $frameNameOrId)->getLocation();
        $this->regionVisibilityStrategy->moveToRegion($this->getPositionProvider(), new Location($locationAsPoint->getX(), $locationAsPoint->getY()));

        $this->driver->switchTo()->frame($frameNameOrId);

        $this->logger->log("Done.");
        $this->checkCurrentFrame($matchTimeout, $tag);

        $this->logger->log("Switching back to parent frame");
        $this->driver->switchTo()->parentFrame();

        $this->regionVisibilityStrategy->returnToOriginalPosition($this->getPositionProvider());

        $this->logger->log("Done!");
    }

    /**
     * @param EyesWebDriver $driver
     * @param string $elementNameOrId
     * @return WebDriverElement
     * @throws \Exception
     */
    public static function findElement(EyesWebDriver $driver, $elementNameOrId)
    {
        $elements = $driver->findElementsByName($elementNameOrId);
        if (count($elements) === 0) {
            $elements = $driver->findElementsById($elementNameOrId);
            if (count($elements) === 0) {
                throw new \Exception("Couldn't find element by name or id '$elementNameOrId'");
            }
        }

        return $elements[0];
    }

    /*
    /**
     * Matches the frame given by the frames path, by switching into the frame
     * and using stitching to get an image of the frame.
     * @param framePath The path to the frame to check. This is a list of
     *                  frame names/IDs (where each frame is nested in the
     *                  previous frame).
     * @param matchTimeout The amount of time to retry matching (milliseconds).
     * @param tag An optional tag to be associated with the match.
     */
    /*  public void checkFrame(String[] framePath, int matchTimeout, String tag) {
          if (getIsDisabled()) {
              logger.log(String.format(
                      "checkFrame(framePath, %d, '%s'): Ignored",
                      matchTimeout,
                      tag));
              return;
          }
          ArgumentGuard.notNull(framePath, "framePath");
          ArgumentGuard.greaterThanZero(framePath.length, "framePath.length");
          logger.log(String.format(
                  "checkFrame(framePath, %d, '%s')", matchTimeout, tag));
          FrameChain originalFrameChain = driver.getFrameChain();
          // We'll switch into the PARENT frame of the frame we want to check,
          // and call check frame.
          logger.verbose("Switching to parent frame according to frames path..");
          String[] parentFramePath = new String[framePath.length-1];
          System.arraycopy(framePath, 0, parentFramePath, 0,
              parentFramePath.length);
          ((EyesTargetLocator)(driver.switchTo())).frames(parentFramePath);
          logger.verbose("Done! Calling checkFrame..");
          checkFrame(framePath[framePath.length - 1], matchTimeout, tag);
          logger.verbose("Done! switching to default content..");
          driver.switchTo().defaultContent();
          logger.verbose("Done! Switching back into the original frame..");
          ((EyesTargetLocator)(driver.switchTo())).frames(originalFrameChain);
          logger.verbose("Done!");
      }
  
  */
    /**
     * Switches into the given frame, takes a snapshot of the application under
     * test and matches a region specified by the given selector.
     *
     * @param array $framePath The path to the frame to check. This is a list of
     *                  frame names/IDs (where each frame is nested in the
     *                  previous frame).
     * @param WebDriverBy $selector A Selector specifying the region to check.
     * @param int $matchTimeout The amount of time to retry matching (milliseconds).
     * @param string $tag An optional tag to be associated with the snapshot.
     * @param bool $stitchContent Whether or not to stitch the internal content of
     *                      the region (i.e., perform
     *                      {@link #checkElement(By, int, String)} on the
     *                      region.
     */
    public function checkRegionInFramePath($framePath = array(), WebDriverBy $selector,
                                           $matchTimeout = null, $tag,
                                           $stitchContent = false)
    {
        if ($this->getIsDisabled()) {
            $this->logger->log(sprintf("checkRegionInFrame(framePath, selector, %d, '%s'): Ignored", $matchTimeout, $tag));
            return;
        }
        if (empty($matchTimeout)) {
            $matchTimeout = self::USE_DEFAULT_MATCH_TIMEOUT;
        }
        ArgumentGuard::notNull($framePath, "framePath");
        ArgumentGuard::greaterThanZero($framePath['length'], "framePath.length");
        $this->logger->log(sprintf("checkFrame(framePath, %d, '%s')", $matchTimeout, $tag));
        //$originalFrameChain = $this->driver->getFrameChain();
        // We'll switch into the PARENT frame of the frame we want to check, and call check frame.
        $this->logger->log("Switching to parent frame according to frames path..");

        $parentFramePath = $framePath; // this actually copies the array.
        $lastFrame = array_pop($parentFramePath);

//        ((EyesTargetLocator)(driver.switchTo())).frames(parentFramePath);
//???????

        $this->logger->log("Done! Calling checkRegionInFrame..");
        $this->checkRegionInFrame($lastFrame, $selector, $matchTimeout, $tag, $stitchContent);
        $this->logger->log("Done! switching back to default content..");
        $this->driver->switchTo()->defaultContent();
        $this->logger->log("Done! Switching into the original frame..");
//???????        ((EyesTargetLocator)(driver.switchTo())).frames(originalFrameChain);
        $this->logger->log("Done!");
    }

    /**
     * Takes a snapshot of the application under test and matches a specific
     * element with the expected region output.
     *
     * @param mixed $element The element to check.
     * @param int $matchTimeout The amount of time to retry matching. (Milliseconds)
     * @param string $tag An optional tag to be associated with the snapshot.
     * @throws TestFailedException if a mismatch is detected and immediate failure reports are enabled
     */
    protected function checkElement($element, $matchTimeout = null, $tag = null)
    {
        $originalOverflow = null;

        // Since the element might already have been found using EyesWebDriver.
        if ($element instanceof EyesRemoteWebElement) {
            $eyesElement = $element;
        } else {
            $eyesElement = new EyesRemoteWebElement($this->logger, $this->driver, $this->findElementMixed($element));
        }

        $originalPositionProvider = $this->getPositionProvider();
        $scrollPositionProvider = new ScrollPositionProvider($this->logger, $this->driver);
        $originalScrollPosition = $scrollPositionProvider->getCurrentPosition();
        $loc = $eyesElement->getLocation();
        $scrollPositionProvider->setPosition(new Location($loc->getX(), $loc->getY()));

        try {
            $this->checkFrameOrElement = true;
            $this->setPositionProvider(new ElementPositionProvider($this->logger, $this->driver, $element));

            $originalOverflow = $eyesElement->getOverflow();

            // Set overflow to "hidden".
            $eyesElement->setOverflow("hidden");

            $elementRegion = $eyesElement->getClientAreaBounds();
            $this->logger->log("Element region: $elementRegion");

            $this->regionToCheck = new RegionProvider($elementRegion);
            $this->regionToCheck->setCoordinatesType(CoordinatesType::CONTEXT_RELATIVE);
            parent::checkWindowBase($this->regionToCheck, $tag, false, $matchTimeout);
        } finally {
            if ($originalOverflow != null) {
                $eyesElement->setOverflow($originalOverflow);
            }

            $this->checkFrameOrElement = false;
            $scrollPositionProvider->setPosition($originalScrollPosition);
            $this->setPositionProvider($originalPositionProvider);
            $this->regionToCheck = null;
        }
    }

    /**
     * Adds a mouse trigger.
     *
     * @param string $action Mouse action.
     * @param Region $control The control on which the trigger is activated (context relative coordinates).
     * @param Location $cursor The cursor's position relative to the control.
     */
    public function addMouseTriggerCursor($action, Region $control, Location $cursor)
    {
        if ($this->getIsDisabled()) {
            $this->logger->log("Ignoring $action (disabled)");
            return;
        }

        // Triggers are actually performed on the previous window.
        if ($this->lastScreenshot == null) {
            $this->logger->log("Ignoring $action (no screenshot)");
            return;
        }

        /** @var EyesWebDriverScreenshot $eyesWebDriverScreenshot */
        $eyesWebDriverScreenshot = $this->lastScreenshot;
        if (!FrameChain::isSameFrameChain($this->driver->getFrameChain(), $eyesWebDriverScreenshot->getFrameChain())) {
            $this->logger->log("Ignoring $action (different frame)");
            return;
        }
        $this->addMouseTriggerBase($action, $control, $cursor);
    }

    /**
     * Adds a mouse trigger.
     *
     * @param string $action Mouse action.
     * @param WebDriverElement $element The WebElement on which the click was called.
     */
    public function addMouseTriggerElement($action, WebDriverElement $element)
    {
        if ($this->getIsDisabled()) {
            $this->logger->log("Ignoring $action (disabled)");
            return;
        }

        ArgumentGuard::notNull($element, "element");

        $pl = $element->getLocation();
        $ds = $element->getSize();

        $elementRegion = Region::CreateFromLTWH($pl->getX(), $pl->getY(), $ds->getWidth(), $ds->getHeight());

        // Triggers are actually performed on the previous window.
        if ($this->lastScreenshot == null) {
            $this->logger->log("Ignoring $action (no screenshot)");
            return;
        }

        /** @var EyesWebDriverScreenshot $eyesWebDriverScreenshot */
        $eyesWebDriverScreenshot = $this->lastScreenshot;

        if (!FrameChain::isSameFrameChain($this->driver->getFrameChain(), $eyesWebDriverScreenshot->getFrameChain())) {
            $this->logger->log("Ignoring $action (different frame)");
            return;
        }

        // Get the element region which is intersected with the screenshot,
        // so we can calculate the correct cursor position.
        $elementRegion = $eyesWebDriverScreenshot->getIntersectedRegion($elementRegion, CoordinatesType::CONTEXT_RELATIVE);

        $this->addMouseTriggerBase($action, $elementRegion, $elementRegion->getMiddleOffset());
    }

    /**
     * Adds a keyboard trigger.
     *
     * @param Region $control The control's context-relative region.
     * @param string $text The trigger's text.
     */
    protected function addTextTriggerControl(Region $control, $text)
    {
        if ($this->getIsDisabled()) {
            $this->logger->verbose("Ignoring '$text' (disabled)");
            return;
        }

        if ($this->lastScreenshot == null) {
            $this->logger->log("Ignoring '$text' (no screenshot)");
            return;
        }

        /** @var EyesWebDriverScreenshot $eyesWebDriverScreenshot */
        $eyesWebDriverScreenshot = $this->lastScreenshot;

        if (!FrameChain::isSameFrameChain($this->driver->getFrameChain(), $eyesWebDriverScreenshot->getFrameChain())) {
            $this->logger->log("Ignoring '$text' (different frame)");
            return;
        }
        $this->addTextTriggerBase($control, $text);
    }

    /**
     * Adds a keyboard trigger.
     *
     * @param WebDriverElement $element The element for which we sent keys.
     * @param string $text The trigger's text.
     */
    protected function addTextTriggerElement(WebDriverElement $element, $text)
    {
        if ($this->getIsDisabled()) {
            $this->logger->log("Ignoring '$text' (disabled)");
            return;
        }

        ArgumentGuard::notNull($element, "element");

        $pl = $element->getLocation();
        $ds = $element->getSize();

        $elementRegion = Region::CreateFromLTWH($pl->getX(), $pl->getY(), $ds->getWidth(), $ds->getHeight());

        $this->addTextTriggerControl($elementRegion, $text);
    }

    /**
     * Call this method if for some
     * reason you don't want to call {@link #open(WebDriver, String, String)}
     * (or one of its variants) yet.
     *
     * @param WebDriver $driver The driver to use for getting the viewport.
     * @return RectangleSize The viewport size of the current context.
     */
    public function getViewportSize(WebDriver $driver = null)
    {
        if (!empty($driver)) {
            ArgumentGuard::notNull($this->driver, "driver");
            return EyesSeleniumUtils::extractViewportSize($this->logger, $this->driver);
        } else {
            ArgumentGuard::isValidState($this->getIsOpen(), "Eyes not open");
            return $this->driver->getDefaultContentViewportSize();
        }
    }

    /**
     * Use this method only if you made a previous call to {@link #open
     * (WebDriver, String, String)} or one of its variants.
     *
     * {@inheritDoc}
     */
    protected function setViewportSize(RectangleSize $size, WebDriver $driver = null)
    {
        if (!empty($driver)) {
            ArgumentGuard::notNull($driver, "driver");
            EyesSeleniumUtils::setViewportSize(new Logger(new PrintLogHandler()), $driver, $size);
            return;
        }

        // ArgumentGuard::isValidState($this->getIsOpen(), "Eyes not open");

        //$originalFrame = $this->driver->getFrameChain();
        //FIXME //$this->driver->switchTo()->defaultContent();

        try {
            EyesSeleniumUtils::setViewportSize($this->logger, $this->driver, $size);
        } catch (EyesException $e) {
            // Just in case the user catches this error
            /*(EyesTargetLocator)*/
            //FIXME ///$this->driver->switchTo()->frames($originalFrame);

            throw new \Exception("Failed to set the viewport size"/*, $e*/);
        }
        /*(EyesTargetLocator)*/
//FIXME //$this->driver->switchTo()->frames($originalFrame);
        /*FIXME */ //$this->viewportSize = new RectangleSize(450, 300);
        $this->viewportSize = new RectangleSize($size->getWidth(), $size->getHeight());
    }


    public function getScreenshot()
    {
        $this->logger->log("getScreenshot()");

        $scaleProviderFactory = $this->updateScalingParams();

        $originalOverflow = null;
        if ($this->hideScrollbars) {
            $originalOverflow = EyesSeleniumUtils::hideScrollbars($this->driver, 200);
        }
        try {
            $imageProvider = new TakesScreenshotImageProvider($this->logger, $this->driver);
            $screenshotFactory = new EyesWebDriverScreenshotFactory($this->logger, $this->driver);

            if ($this->checkFrameOrElement) {
                $this->logger->log("Check frame/element requested");
                $algo = new FullPageCaptureAlgorithm($this->logger);

                if ($this->getStitchMode() == StitchMode::CSS) {
                    $originProvider = new CssTranslatePositionProvider($this->logger, $this->driver);
                } else {
                    $originProvider = $this->positionProvider;
                }

                $entireFrameOrElement = $algo->getStitchedRegion($imageProvider, $this->regionToCheck,
                    $originProvider, $this->getPositionProvider(),
                    $scaleProviderFactory,
                    $this->getWaitBeforeScreenshots(), $this->debugScreenshotsProvider, $screenshotFactory);
                $this->logger->log("Building screenshot object...");

                $result = new EyesWebDriverScreenshot($this->logger, $this->driver, $entireFrameOrElement,
                    null, null, new RectangleSize($entireFrameOrElement->width(), $entireFrameOrElement->height()));
            } else if ($this->forceFullPageScreenshot) {
                $this->logger->log("Full page screenshot requested.");
                // Save the current frame path.
                $originalFrame = $this->driver->getFrameChain();
                $this->driver->switchTo()->defaultContent();
                $algo = new FullPageCaptureAlgorithm($this->logger);
                $regionProvider = new RegionProvider();

                $fullPageImage = $algo->getStitchedRegion($imageProvider, $regionProvider,
                    new ScrollPositionProvider($this->logger, $this->driver),
                    $this->positionProvider, $scaleProviderFactory,
                    $this->getWaitBeforeScreenshots(), $this->debugScreenshotsProvider, $screenshotFactory);

                $this->driver->switchTo()->frames($originalFrame);
                $result = new EyesWebDriverScreenshot($this->logger, $this->driver, $fullPageImage);
            } else {
                $this->logger->verbose("Screenshot requested...");
                $screenshot64 = $this->driver->getScreenshotAsBase64();
                $this->logger->log("Done! Creating image object...");
                $screenshotImage = $screenshot64;

                $this->debugScreenshotsProvider->save($screenshotImage, "original");

                $this->logger->log("Done!");

                /** @var ScaleProvider $scaleProvider */
                $scaleProvider = $this->scaleProviderHandler->get();
                $screenshotImage = $scaleProvider->scaleImage($screenshotImage);
                $this->debugScreenshotsProvider->save($screenshotImage, "scaled");

                //$screenshotImage = $screenshotImage->crop($this->left, $this->top, $image->width() - $this->left - $this->right, $image->height() - $this->top - $this->bottom);
                //$screenshotImage = $this->cutProviderHandler->get()->cut($screenshotImage);
                //$this->debugScreenshotsProvider->save($screenshotImage, "cut");

                $this->logger->verbose("Creating screenshot object...");
                $result = new EyesWebDriverScreenshot($this->logger, $this->driver, $screenshotImage);
            }
            $this->logger->verbose("Done!");
            return $result;
        } finally {
            if ($this->hideScrollbars) {
                EyesSeleniumUtils::setOverflow($this->driver, $originalOverflow);
            }
        }
    }

    public function getTitle()
    {
        if (!$this->doNotGetTitle) {
            try {
                return $this->driver->getTitle();
            } catch (\Exception $ex) {
                $this->logger->log("failed (" . $ex->getMessage() . ")");
                $this->doNotGetTitle = true;
            }
        }

        return "";
    }

    protected function getInferredEnvironment()
    {
        $userAgent = EyesSeleniumUtils::getUserAgent($this->driver, $this->logger);
        if ($userAgent != null) {
            return "useragent: $userAgent";
        }

        return null;
    }

    /**
     * {@inheritDoc}
     *
     * This override also checks for mobile operating system.
     */
    protected function getAppEnvironment()
    {
        $appEnv = parent::getAppEnvironment();
        if ($this->driver instanceof EyesWebDriver) {
            $underlyingDriver = $this->driver->getRemoteWebDriver();
        } else {
            $underlyingDriver = $this->driver;
        }
        // If hostOs isn't set, we'll try and extract and OS ourselves.
        if ($appEnv->getOs() == null) {
            $this->logger->log("No OS set, checking for mobile OS...");
            if (EyesSeleniumUtils::isMobileDevice($underlyingDriver)) {
                $platformName = null;
                $this->logger->log("Mobile device detected! Checking device type..");
                if (EyesSeleniumUtils::isAndroid($underlyingDriver)) {
                    $this->logger->log("Android detected.");
                    $platformName = "Android";
                } else if (EyesSeleniumUtils::isIOS($underlyingDriver)) {
                    $this->logger->log("iOS detected.");
                    $platformName = "iOS";
                } else {
                    $this->logger->log("Unknown device type.");
                }
                // We only set the OS if we identified the device type.
                if ($platformName != null) {
                    $os = $platformName;
                    $platformVersion = EyesSeleniumUtils::getPlatformVersion($underlyingDriver);
                    if ($platformVersion != null) {
                        $majorVersion = explode('.', $platformVersion, 2)[0]; //????

                        if (!empty($majorVersion)) {
                            $os .= " " . $majorVersion;
                        }
                    }

                    $this->logger->log("Setting OS: " . $os);
                    $appEnv->setOs($os);
                    $this->logger->verbose("Setting scale method for mobile.");
                    $this->setScaleMethod(ScaleMethod::QUALITY);
                }
            } else {
                $this->logger->log("No mobile OS detected.");
            }
        }
        $this->logger->log("Done!");
        return $appEnv;
    }
}

?>