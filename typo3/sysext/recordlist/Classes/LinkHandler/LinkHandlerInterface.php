<?php
namespace TYPO3\CMS\Recordlist\LinkHandler;

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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Recordlist\Controller\LinkBrowserController;

/**
 * Interface for link handlers displayed in the LinkBrowser
 */
interface LinkHandlerInterface
{
    /**
     * @return array
     */
    public function getLinkAttributes();

    /**
     * Initialize the handler
     *
     * @param LinkBrowserController $linkBrowser
     * @param string $identifier
     * @param array $configuration Page TSconfig
     *
     * @return void
     */
    public function initialize(LinkBrowserController $linkBrowser, $identifier, array $configuration);

    /**
     * Checks if this is the handler for the given link
     *
     * The handler may store this information locally for later usage.
     *
     * @param array $linkParts Link parts as returned from TypoLinkCodecService
     *
     * @return bool
     */
    public function canHandleLink(array $linkParts);

    /**
     * Format the current link for HTML output
     *
     * @return string
     */
    public function formatCurrentUrl();

    /**
     * Render the link handler
     *
     * @param ServerRequestInterface $request
     *
     * @return string
     */
    public function render(ServerRequestInterface $request);

    /**
     * Return TRUE if the handler supports to update a link.
     *
     * This is useful for file or page links, when only attributes are changed.
     *
     * @return bool
     */
    public function isUpdateSupported();

    /**
     * @return string[] Array of body-tag attributes
     */
    public function getBodyTagAttributes();
}
