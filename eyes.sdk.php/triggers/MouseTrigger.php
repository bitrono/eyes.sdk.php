<?php
/*
 * Applitools SDK for Selenium integration.
 */

namespace Applitools;

/**
 * Encapsulates a mouse trigger.
 */
class MouseTrigger extends Trigger {
    private $mouseAction; //MouseAction
    private $control; //Region

    /**
     * Relative to the top left corner of {@link #control}, or null if unknown.
     */
    private $location; //Location


    public function __construct($mouseAction, Region $control, Location $location) {

        ArgumentGuard::notNull($mouseAction, "mouseAction");
        ArgumentGuard::notNull($control, "control");

        $this->mouseAction = $mouseAction;
        $this->control = $control;
        $this->location = $location;
    }

    public function getMouseAction() {
        return $this->mouseAction;
    }

    public function getControl() {
        return $this->control;
    }

    public function getLocation() {
        return $this->location;
    }

    public function getTriggerType() {
        return Trigger::Mouse;
    }

    public function toString() {
        return sprintf("%s [%s] %s", $this->mouseAction, $$this->control, $this->location);
    }
}

?>