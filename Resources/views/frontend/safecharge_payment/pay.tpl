{extends file="frontend/index/index.tpl"}

{block name='frontend_index_shop_navigation'}{/block}
{block name='frontend_index_content_left'}{/block}

{block name='frontend_index_content'}
    <div class="content listing--content">
        <div class="hero-unit category--teaser panel has--border is--rounded">
            <div class="hero--text panel--body is--wide">
                <form method="post" id="sc_payment_form">
                    {$formInputs}
                    {if $paymentApi == "cashier"}
                        <h3>
                            <img src="/themes/Frontend/Responsive/frontend/_public/src/img/icons/loading-indicator.gif" style="display: inline-block; margin-right: 10px;" />
                            Please wait, we process your order!
                        </h3>
                        
                        <noscript>
                            <a class="btn is--primary" href="{$cancelUrl}">Cancel order</a>
                            <input type="submit" class="btn is--secondary" value="Pay" />
                        </noscript>

                        <script type="text/javascript">
                            window.addEventListener("load", document.getElementById('sc_payment_form').submit());
                        </script>
                    {else}
                        <h3>Please, select your preffered way to pay:</h3>
                        
                        <div class="panel has--border is--rounded">
                            <div class="panel panel--header">User UPOs</div>
                            
                            {if $upos}
                                <div class="panel panel--body">
                                    <div class="filter--facet-container" style="display: block;">
                                        {foreach $upos as $upo}
                                            <div class="filter-panel filter--multi-selection" data-filter-type="value-list">
                                                <div class="filter-panel--flyout">
                                                    <input type="radio" id="sc_payment_method_{$upo.paymentMethod}" name="payment_method_sc" value="{$upo.paymentMethod}" style="display: none;">
                                                    
                                                    <label class="filter-panel--title" for="sc_payment_method_{$upo.paymentMethod}"  onclick="scShowCheckMark($(this))">
                                                        <i class="icon--check" style="position: relative; bottom: 10px; color: green; display: none;"></i>
                                                        <img src="{$upo.logoURL}" alt="{$upo.paymentMethodDisplayName[0].message}" style="display: inline-block;" />
                                                    </label>
                                                    
                                                    {if $upo.fields}
                                                        <span class="filter-panel--icon"></span>

                                                        <div class="filter-panel--content">
                                                            <ul class="filter-panel--option-list">
                                                                {foreach $upo.fields as $fields}
                                                                    <li class="filter-panel--option">
                                                                        <input id="{$upo.paymentMethod}_{$fields.name}" name="{$upo.paymentMethod}[{$fields.name}]" type="{$fields.type}" {if $fields.regex}pattern="{$fields.regex}"{/if} placeholder="{$fields.caption[0].message}" style="width: 93%; margin-top: 5px;" />
                                                                        
                                                                        {if $fields.regex}
                                                                            <i class="icon--question" onclick="$('#error_sc_{$fields.name}').toggle()" style="cursor: pointer;"></i>
                                                                            <label class="upo_error help-block" id="error_sc_{$fields.name}" style="display: none;">{$fields.validationmessage[0].message}</label>
                                                                        {/if}
                                                                    </li>
                                                                {/foreach}
                                                            </ul>
                                                        </div>
                                                    {/if}
                                                </div>
                                            </div>
                                        {/foreach}
                                    </div>
                                </div>
                            {/if}
                        </div>
                        <br/>
                        
                        <div class="panel has--border is--rounded">
                            <div class="panel panel--header">APMs</div>
                            
                            {if $apms}
                                <div class="panel panel--body">
                                    <div class="filter--facet-container" style="display: block;">
                                        {foreach $apms as $apm}
                                            <div class="filter-panel filter--multi-selection" data-filter-type="value-list">
                                                <div class="filter-panel--flyout">
                                                    <input type="radio" id="sc_payment_method_{$apm.paymentMethod}" name="payment_method_sc" value="{$apm.paymentMethod}" style="display: none;">
                                                    
                                                    <label class="filter-panel--title" for="sc_payment_method_{$apm.paymentMethod}"  onclick="scShowCheckMark($(this))">
                                                        <i class="icon--check" style="position: relative; bottom: 10px; color: green; display: none;"></i>
                                                        <img src="{$apm.logoURL}" alt="{$apm.paymentMethodDisplayName[0].message}" style="display: inline-block;" />
                                                    </label>
                                                    
                                                    {if $apm.fields}
                                                        <span class="filter-panel--icon"></span>

                                                        <div class="filter-panel--content">
                                                            <ul class="filter-panel--option-list">
                                                                {foreach $apm.fields as $fields}
                                                                    <li class="filter-panel--option">
                                                                        <input id="{$apm.paymentMethod}_{$fields.name}" name="{$apm.paymentMethod}[{$fields.name}]" type="{$fields.type}" {if $fields.regex}pattern="{$fields.regex}"{/if} placeholder="{$fields.caption[0].message}" style="width: 93%; margin-top: 5px;" />
                                                                        
                                                                        {if $fields.regex}
                                                                            <i class="icon--question" onclick="$('#error_sc_{$fields.name}').toggle()" style="cursor: pointer;"></i>
                                                                            <label class="apm_error help-block" id="error_sc_{$fields.name}" style="display: none;">{$fields.validationmessage[0].message}</label>
                                                                        {/if}
                                                                    </li>
                                                                {/foreach}
                                                            </ul>
                                                        </div>
                                                    {/if}
                                                </div>
                                            </div>
                                        {/foreach}
                                    </div>
                                </div>
                            {/if}
                        </div>
                        <br/>
                        
                        <a class="btn is--primary" href="{$cancelUrl}">Cancel order</a>
                        <input type="button" class="btn is--secondary" value="Pay" onclick="scVerifyFields()" />
                        
                        <script type="text/javascript">
                            // show checkmark on selected payment method
                            function scShowCheckMark(_this) {
                                _this.closest('.panel--body').find('i.icon--check').hide();
                                _this.find('i.icon--check').show();
                            }
                            
                            // check if payment method is selected and its fields are filled
                            function scVerifyFields() {
                                
                            }
                        </script>
                    {/if}
                </form>
            </div>
        </div>
    </div>
{/block}
