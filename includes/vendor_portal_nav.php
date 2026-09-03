<?php
declare(strict_types=1);

function vendor_portal_nav(PDO $pdo,array $vendor,string $active):void
{
    $slug=(string)$vendor['slug'];$isAdmin=vendor_admin_can_access($pdo,(int)$vendor['id']);$isRestaurant=($vendor['service_model']??'kiosk')==='restaurant';
    $links=['orders'=>['Orders','/vendor/'.rawurlencode($slug)]];
    if($isRestaurant)$links['tables']=['Tables','/vendor/'.rawurlencode($slug).'/tables'];
    if($isAdmin){$links['products']=['Products','/product/'.rawurlencode($slug)];$links['reports']=['Reports','/vendor/'.rawurlencode($slug).'/reports'];}
    echo '<nav class="vendor-portal-nav" aria-label="Vendor administration">';
    foreach($links as $key=>[$label,$url])echo '<a'.($active===$key?' class="active" aria-current="page"':'').' href="'.e($url).'">'.e($label).'</a>';
    echo '</nav>';
}
