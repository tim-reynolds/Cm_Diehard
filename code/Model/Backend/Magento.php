<?php
/**
 * This cache backend uses Magento's main cache storage for full page cache.
 *
 * Pros:
 *   - Drop-in and go, no additional requirements
 *   - Offers additional flexibility when determining if cached response should be used
 *   - Cache clearing and invalidation is handled instantly and automatically
 *   - Experimental: can do dynamic replacement without using Ajax
 * Cons:
 *   - Magento is still loaded (but, controller is only dispatched when necessary)
 *   - Will probably increase size of Magento's cache considerably
 *
 * TODO: extend this with a version which uses a separate cache backend so primary cache is not affected
 *
 * To use this backend you must add it to the cache request processors in app/etc/local.xml:
 *
 * <cache>
 *   <request_processors>
 *     <diehard>Cm_Diehard_Model_Backend_Magento</diehard>
 *   </request_processors>
 *   ...
 * </cache>
 *
 * @package     Cm_Diehard
 * @author      Colin Mollenhour
 */
class Cm_Diehard_Model_Backend_Magento extends Cm_Diehard_Model_Backend_Abstract
{

    protected $_name = 'Magento';

    /* Supported methods: */
    protected $_useAjax = TRUE;
    protected $_useEsi  = TRUE;
    protected $_useJs   = TRUE;

    protected $_useCachedResponse = NULL;

    /**
     * Clear all cached pages
     *
     * @return void
     */
    public function flush()
    {
        Mage::app()->getCacheInstance()->cleanType('diehard');
    }

    /**
     * @param  $tags
     * @return void
     */
    public function cleanCache($tags)
    {
        // No additional cleaning necessary
    }

    /**
     * Save the response body in the cache before sending it.
     *
     * @param Mage_Core_Controller_Response_Http $response
     * @param  $lifetime
     * @return void
     */
    public function httpResponseSendBefore(Mage_Core_Controller_Response_Http $response, $lifetime)
    {
        // Do not overwrite cached response if response was pulled from cache or cached response
        // was invalidated by an observer of the `diehard_use_cached_response` event
        if ($this->getUseCachedResponse() === NULL)
        {
            $cacheKey = $this->getCacheKey();
            $tags = $this->helper()->getTags();
            $tags[] = Cm_Diehard_Helper_Data::CACHE_TAG;
            Mage::app()->saveCache($response->getBody(), $cacheKey, $tags, $lifetime);
        }

        // Inject dynamic content replacement at end of body
        $body = $response->getBody('default');
        if ($this->useJs()) {
            $body = $this->injectDynamicBlocks($body);
            $response->setBody($body, 'default');
        }
    }

    /**
     * Observers of the `diehard_use_cached_response` event may use this to disallow sending of
     * a cached response for any given request.
     *
     * @param bool $flag
     */
    public function setUseCachedResponse($flag)
    {
        $this->_useCachedResponse = $flag;
    }

    /**
     * @return bool
     */
    public function getUseCachedResponse()
    {
        return $this->_useCachedResponse;
    }

    /**
     * This method is called by Mage_Core_Model_Cache->processRequest()
     *
     * @param  string|bool $content
     * @return bool
     */
    public function extractContent($content)
    {
        $cacheKey = $this->getCacheKey();
        if(Mage::app()->getCacheInstance()->getFrontend()->test($cacheKey)) {
            $this->setUseCachedResponse(TRUE);

            // Allow external code to cancel the sending of a cached response
            if (0 /* TODO optional events_enabled feature */) {
                Mage::app()->loadAreaPart(Mage_Core_Model_App_Area::AREA_FRONTEND, Mage_Core_Model_App_Area::PART_CONFIG);
                Mage::app()->loadAreaPart(Mage_Core_Model_App_Area::AREA_FRONTEND, Mage_Core_Model_App_Area::PART_EVENTS);
                Mage::dispatchEvent('diehard_use_cached_response', array('backend' => $this));
            }

            if($this->getUseCachedResponse()) {
                $body = Mage::app()->loadCache($cacheKey);
                // Inject dynamic content replacement at end of body
                $body = $this->injectDynamicBlocks($body);
                return $body;
            }
        }
        return FALSE;
    }

    /**
     * @param $body
     * @return string
     */
    public function injectDynamicBlocks($body)
    {
        if ($params = $this->extractParamsFromBody($body)) {
            $dynamic = $this->getDynamicBlockReplacement($params);
            $_body = $this->replaceParamsInBody($body, $dynamic);
            if ($_body) {
                return $_body;
            }
        }
        return $body;
    }

    /**
     * Calls the diehard/load controller without spawning a new request
     *
     * @param array $params
     * @return string
     */
    public function getDynamicBlockReplacement($params)
    {
        // Append dynamic block content to end of page to be replaced by javascript, but not Ajax
        if($params['blocks'] || ! empty($params['all_blocks']))
        {
            // Init store if it has not been yet (page served from cache)
            if( ! Mage::app()->getFrontController()->getData('action')) {
                $appParams = Mage::registry('application_params');
                Mage::app()->init($appParams['scope_code'], $appParams['scope_type'], $appParams['options']);
            }
            // Reset parts of app if it has been init'ed (page not served from cache but being saved to cache)
            else {
                // Reset layout
                Mage::unregister('_singleton/core/layout');
                Mage::getSingleton('core/layout');
                // TODO Mage::app()->getLayout() is not reset using the method above!
                // TODO Consider resetting Magento entirely using Mage::reset();
            }

            // Create a subrequest to get JSON response
            $request = new Mage_Core_Controller_Request_Http('_diehard/load/json');
            $request->setModuleName('_diehard');
            $request->setControllerName('load');
            $request->setActionName('ajax');
            $request->setControllerModule('Cm_Diehard');
            $request->setParam('full_action_name', $params['full_action_name']);
            if ( ! empty($params['all_blocks'])) {
                $request->setParam('all_blocks', 1);
            } else {
                $request->setParam('blocks', $params['blocks']);
            }
            $request->setParam('params', $params['params']);
            $response = new Mage_Core_Controller_Response_Http;
            $controller = new Cm_Diehard_LoadController($request, $response);

            // Disable cache, render replacement blocks and re-enable
            $oldLifetime = $this->helper()->getLifetime();
            $this->helper()->setLifetime(FALSE);
            $controller->preDispatch();
            $controller->dispatch('json');
            $this->helper()->setLifetime($oldLifetime);

            return "<script type=\"text/javascript\">Diehard.replaceBlocks({$response->getBody()});</script>";
        }

        // No dynamic blocks at this time
        else {
            return '';
        }
    }

}
