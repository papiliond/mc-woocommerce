<?php

/**
 * Created by Vextras.
 *
 * Name: Ryan Hungate
 * Email: ryan@vextras.com
 * Date: 7/13/16
 * Time: 8:29 AM
 */
class MailChimp_WooCommerce_Transform_Products
{
    /**
     * @param int $page
     * @param int $limit
     * @return \stdClass
     */
    public function compile($page = 1, $limit = 5)
    {
        $response = (object) array(
            'endpoint' => 'products',
            'page' => $page ? $page : 1,
            'limit' => (int) $limit,
            'count' => 0,
            'stuffed' => false,
            'items' => array(),
        );

        if ((($products = $this->getProductPostsIds($page, $limit)) && !empty($products))) {
            foreach ($products as $post_id) {
                $response->items[] = $post_id;
                $response->count++;
            }
        }

        $response->stuffed = ($response->count > 0 && (int) $response->count === (int) $limit) ? true : false;

        return $response;
    }

    /**
     * @param MailChimp_WooCommerce_LineItem $item
     * @return MailChimp_WooCommerce_Product
     */
    public function fromOrderItem(MailChimp_WooCommerce_LineItem $item)
    {
        $product = new MailChimp_WooCommerce_Product();

        $fallback_title = $item->getFallbackTitle();
        if (empty($fallback_title)) $fallback_title = "deleted_{$item->getProductId()}";

        $product->setId($item->getProductId());
        $product->setTitle($fallback_title);

        $variant = new MailChimp_WooCommerce_ProductVariation();
        $variant->setId($item->getProductId());
        $variant->setTitle($fallback_title);
        $variant->setInventoryQuantity(0);
        $variant->setVisibility('hidden');
        $variant->setSku($item->getFallbackSku());

        $product->addVariant($variant);

        return $product;
    }

    /**
     * @param WP_Post $post
     * @return MailChimp_WooCommerce_Product
     */
    public function transform(WP_Post $post, $fallback_title = null)
    {
        if (!($woo = wc_get_product($post))) {
            return $this->wooProductNotLoadedCorrectly($post, $fallback_title);
        }

        $variant_posts = $this->getProductVariantPosts($post->ID);

        $variants = $variant_posts ? array_merge(array($woo), $variant_posts) : array($woo);

        $is_variant = count($variants) > 1;

        $product = new MailChimp_WooCommerce_Product();

        $product->setId($woo->get_id());
        $product->setHandle($post->post_name);
        $product->setImageUrl($this->getProductImage($post));
        $product->setDescription($post->post_content);
        $product->setPublishedAtForeign(mailchimp_date_utc($post->post_date));
        $product->setTitle($woo->get_title());
        $product->setUrl($woo->get_permalink());
        $original_vendor = '';
        if (in_array('woocommerce-product-vendors/woocommerce-product-vendors.php', apply_filters('active_plugins', get_option('active_plugins'))) || defined('WC_PRODUCT_VENDORS_VERSION') ){ 
            $vendor_id = WC_Product_Vendors_Utils::get_vendor_id_from_product($woo->get_id() );
            $vendor_data = WC_Product_Vendors_Utils::get_vendor_data_by_id( $vendor_id );
            $original_vendor = $vendor_data['name'];
        }
        $vendor_filter = apply_filters('mailchimp_sync_product_vendor', $original_vendor, $product);
        if ($original_vendor != '' && is_string($vendor_filter)) {
            $product->setVendor($vendor_filter);
        } else if ($original_vendor != '' && is_string($original_vendor)) {
            $product->setVendor($original_vendor);
        } else {
            /**
             * Insert the category labels you want to add as vendor (only one per product)
             */
            $important_category_labels = array("Nespresso kompatibilis kávékapszulák", "Szemes kávék");
            $categoryList = strip_tags(wc_get_product_category_list($woo->get_id(), ", ", "", ""));

            $category = "";
            foreach ($important_category_labels as &$label) {
                if (strpos($categoryList, $label) !== false) {
                    $category = $label;
                    break;
                }
            }

            // Set the product category as vendor
            $product->setVendor($category);
        }

        foreach ($variants as $variant) {

            $product_variant = $this->variant($variant, $woo->get_title());

            if (!$product_variant) continue;

            $product_variant_title = $product_variant->getTitle();

            if (empty($product_variant_title)) {
                $product_variant->setTitle($woo->get_title());
            }

            $product_variant_image = $product_variant->getImageUrl();

            if (empty($product_variant_image)) {
                $product_variant->setImageUrl($product->getImageUrl());
            }

            $product->addVariant($product_variant);
        }

        return $product;
    }

    /**
     * @param WP_Post $post
     * @param string $fallback_title
     * @return MailChimp_WooCommerce_ProductVariation
     */
    public function variant($post, $fallback_title = null)
    {
        if ($post instanceof WC_Product || $post instanceof WC_Product_Variation) {
            $woo = $post;
        } else {
            if (isset($post->post_type) && $post->post_type === 'product_variation') {
                $woo = new WC_Product_Variation($post->ID);
            } else {
                $woo = wc_get_product($post);
            }
        }

        $variant = new MailChimp_WooCommerce_ProductVariation();

        if (!$woo) {
            //mailchimp_error("products.transform", "could not load product variant", array('post' => print_r($post, true)));
            return $variant;
        }

        $variant->setId($woo->get_id());
        $variant->setUrl($woo->get_permalink());
        $variant->setImageUrl($this->getProductImage($post));
        $variant->setPrice($woo->get_price());
        $variant->setSku($woo->get_sku());
        $variant->setBackorders($woo->backorders_allowed());

        if (empty($variant->getTitle())) {
            if (!empty($fallback_title)) {
                $variant->setTitle($fallback_title);
            } elseif (!empty($variant->getSku())) {
                $variant->setTitle($variant->getSku());
            }
        }

        // only set these properties if the product is currently visible or purchasable.
        if ($woo->is_purchasable() && $woo->is_visible()) {
            if ($woo->is_in_stock()) {
                $variant->setInventoryQuantity(($woo->managing_stock() ? $woo->get_stock_quantity() : 1000000));
            } else {
                $variant->setInventoryQuantity(0);
            }
        } else {
            $variant->setInventoryQuantity(0);
        }

        if ($woo instanceof WC_Product_Variation) {

            $variation_title = $woo->get_title();
            if (empty($variation_title)) $variation_title = $fallback_title;

            $title = array($variation_title);

            foreach ($woo->get_variation_attributes() as $attribute => $value) {
                if (is_string($value) && !empty($value)) {
                    $name = ucfirst(str_replace(array('attribute_pa_', 'attribute_'), '', $attribute));
                    $title[] = "$name = $value";
                }
            }

            $variant->setTitle(implode(' :: ', $title));
            $variant->setVisibility(($woo->variation_is_visible() ? 'visible' : ''));
        } else {
            $variant->setVisibility(($woo->is_visible() ? 'visible' : ''));
            $variant->setTitle($woo->get_title());
        }

        return $variant;
    }

    /**
     * @param int $page
     * @param int $posts
     * @return array|bool
     */
    public function getProductPostsIds($page = 1, $posts = 5)
    {
        $offset = 0;

        if ($page > 1) {
            $offset = (($page-1) * $posts);
        }

        $params = array(
            'post_type' => array_merge(array_keys(wc_get_product_types()), array('product')),
            'posts_per_page' => $posts,
            'post_status' => array('private', 'publish', 'draft'),
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids'
        );

        $products = get_posts($params);

        if (empty($products)) {
            sleep(2);
            $products = get_posts($params);
            if (empty($products)) {
                return false;
            }
        }

        return $products;
    }

    /**
     * @param $id
     * @return array|bool
     */
    public function getProductVariantPosts($id)
    {
        $variants = get_posts(array(
            'post_type' => 'product_variation',
            'post_status' => array('private', 'publish', 'draft'),
            'numberposts' => -1,
            'post_parent' => $id
        ));

        if (empty($variants)) {
            return false;
        }

        return $variants;
    }

    public function getProductImage($post)
    {
        $id = is_a($post, 'WC_Product') ? $post->get_id() : $post->ID;
        $meta = get_post_meta($id);
        $key = '_thumbnail_id';
        $image_key = $this->getProductImageKey();
        if ($meta && is_array($meta) && array_key_exists($key, $meta) && isset($meta[$key][0])) {
            $img = wp_get_attachment_image_src($meta[$key][0], $image_key);
            if (!empty($img[0])) {
                if (substr($img[0], 0, 4) !== 'http') {
                    return rtrim(home_url(), '/').'/'.ltrim($img[0], '/');
                }
                return $img[0];
            }
        }
        return get_the_post_thumbnail_url($id, $image_key);
    }

    /**
     * @return null|string
     */
    public function getProductImageKey()
    {
        return mailchimp_get_option('mailchimp_product_image_key', 'medium');
    }

    /**
     * @param $id
     * @return bool|MailChimp_WooCommerce_Product
     * @throws Exception
     */
    public static function deleted($id, $title)
    {
        $store_id = mailchimp_get_store_id();
        $api = mailchimp_get_api();

        if (!($product = $api->getStoreProduct($store_id, "deleted_{$id}"))) {
            $product = new MailChimp_WooCommerce_Product();

            $product->setId("deleted_{$id}");
            $product->setTitle($title);

            $variant = new MailChimp_WooCommerce_ProductVariation();
            $variant->setId($product->getId());
            $variant->setTitle($title);
            $variant->setInventoryQuantity(0);
            $variant->setVisibility('hidden');

            $product->addVariant($variant);

            return $api->addStoreProduct($store_id, $product);
        }

        return $product;
    }

    /**
     * @param $id
     * @return bool|MailChimp_WooCommerce_Product
     * @throws Exception
     */
    public static function missing_order_item($item)
    {
        // we can only do this with an order item
        if (!$item instanceof WC_Order_Item_Product) return false;

        $store_id = mailchimp_get_store_id();
        $api = mailchimp_get_api();

        // If the $item->get_product_id() is null or 0, we can try to retrieve the id directly from "wc_order_product_lookup" table
        if (!$id = $item->get_product_id()) {
            global $wpdb;
            $query = "SELECT product_id FROM {$wpdb->prefix}wc_order_product_lookup WHERE order_item_id = %s";
            $query_result = $wpdb->get_results( $wpdb->prepare($query, $item->get_id()));
            $id = $query_result[0]->product_id ?: 0;
        }

        $title = $item->get_name();

        // only do this if we haven't pushed this product ID up yet to Mailchimp
        if (!($product = $api->getStoreProduct($store_id, "deleted_{$id}"))) {
            $product = new MailChimp_WooCommerce_Product();

            $product->setId("deleted_{$id}");
            $product->setTitle($title);

            $variant = new MailChimp_WooCommerce_ProductVariation();
            $variant->setId($product->getId());
            $variant->setTitle($title);
            $variant->setInventoryQuantity(0);
            $variant->setVisibility('hidden');

            $product->addVariant($variant);

            return $api->addStoreProduct($store_id, $product);
        }

        return $product;
    }

    /**
     * @param \WP_Post $post
     * @param string|null $fallback_title
     * @return MailChimp_WooCommerce_Product
     */
    protected function wooProductNotLoadedCorrectly($post, $fallback_title = null)
    {
        $product = new MailChimp_WooCommerce_Product();
        $product->setId($post->ID);
        $product->setHandle($post->post_name);
        $product->setDescription($post->post_content);
        $product->setImageUrl($this->getProductImage($post));

        $variant = $this->variant($post, ($post->post_name ? $post->post_name : $fallback_title));

        if (!$variant->getImageUrl()) {
            $variant->setImageUrl($product->getImageUrl());
        }

        $product->addVariant($variant);

        return $product;
    }
}
