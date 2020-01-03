{extends file="frontend/index/index.tpl"}

{* block name='frontend_index_shop_navigation'}{/block *}
{block name='frontend_index_content_left'}{/block}

{block name='frontend_index_content'}
    <div class="content listing--content">
        <div class="hero-unit category--teaser panel has--border is--rounded">
            <div class="hero--text panel--body is--wide">
                <h2>The order fail!</h2>
                <h3>If you want to try again click on the basket above and proceed to checkout.</h3>
                
                {if $message}
                    <i>{s name="frontend/safecharge_payment/cancel_message"}$message{/s}</i>
                {/if}
            </div>
        </div>
    </div>
{/block}
