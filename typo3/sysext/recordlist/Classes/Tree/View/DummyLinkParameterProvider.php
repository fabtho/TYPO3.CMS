<?php
namespace TYPO3\CMS\Recordlist\Tree\View;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * This class is a dummy class used for the FileSystemNavigationFrameController
 */
class DummyLinkParameterProvider implements LinkParameterProviderInterface
{
    /**
     * @var array
     */
    protected $parameters = [];

    /**
     * @var string
     */
    protected $thisScript;

    /**
     * @param string $mode
     * @param string $act
     * @param string $thisScript
     */
    public function __construct($mode, $act, $thisScript)
    {
        if ($mode) {
            $this->parameters['mode'] = $mode;
        }
        if ($act) {
            $this->parameters['act'] = $act;
        }
        $this->thisScript = $thisScript;
    }

    /**
     * @param array $values Array of values to include into the parameters or which might influence the parameters
     *
     * @return string[] Array of parameters which have to be added to URLs
     */
    public function getUrlParameters(array $values)
    {
        return $this->parameters;
    }

    /**
     * @param array $values Values to be checked
     *
     * @return bool Returns TRUE if the given values match the currently selected item
     */
    public function isCurrentlySelectedItem(array $values)
    {
        return false;
    }

    /**
     * Returns the URL of the current script
     *
     * @return string
     */
    public function getScriptUrl()
    {
        return $this->thisScript;
    }
}
