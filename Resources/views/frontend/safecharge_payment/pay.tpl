{namespace name="frontend/safecharge_payment/pay"}

{extends file="frontend/index/index.tpl"}

{block name="frontend_index_header_css_print"}
	<style type="text/css">
		.sc_fields {
			width: 93%;
			margin-top: 5px;
			-webkit-appearance: none;
			-moz-appearance: none;
			appearance: none;
			border-radius: 3px;
			background-clip: padding-box;
			box-sizing: border-box;
			line-height: 19px;
			line-height: 1.1875rem;
			font-size: 14px;
			font-size: .875rem;
			width: 290px;
			width: 18.125rem;
			padding: 10px 10px 9px 10px;
			padding: .625rem .625rem .5625rem .625rem;
			box-shadow: inset 0 1px 1px #dadae5;
			background: #f8f8fa;
				background-position-x: 0%;
				background-position-y: 0%;
				background-repeat: repeat;
				background-attachment: scroll;
				background-image: none;
				background-size: auto;
			border: 1px solid #dadae5;
				border-top-color: rgb(218, 218, 229);
			border-top-color: #cbcbdb;
			color: #8798a9;
			text-align: left;
		}
	</style>
{/block}

{block name='frontend_index_header_javascript_tracking'}
	<script type="text/javascript" src="https://cdn.safecharge.com/safecharge_resources/v1/websdk/safecharge.js"></script>
{/block}

{block name='frontend_index_shop_navigation'}{/block}
{block name='frontend_index_content_left'}{/block}

{block name='frontend_index_content'}
    <div class="content listing--content">
        <div class="hero-unit category--teaser panel has--border is--rounded">
            <div class="hero--text panel--body is--wide">
                <form method="post" id="sc_payment_form">
					<div class="alert is--error is--rounded">
						<div class="alert--icon">
							<i class="icon--element icon--cross"></i>
						</div>
						
						<div class="alert--content">
							Alert message text
							
							<div class="btn icon--cross is--small btn--grey modal--close" 
								 style="float: right" 
								 onclick="$(this).parents('.alert').hide()"></div>
						</div>
					</div>
					
					<h3>{s name="frontend/safecharge_payment/h3"}Please, select your preffered way to pay{/s}:</h3>

					<div class="panel has--border is--rounded">
						{if $apms}
							<div class="panel panel--body">
								<div class="filter--facet-container" style="display: block;">
									<input type="hidden" id="sc_payment_method" name="sc_payment_method" value="" />
									
									{foreach $apms as $apm}
										<div class="filter-panel filter--multi-selection" data-filter-type="value-list">
											<div class="filter-panel--flyout">
												<label class="filter-panel--title" data-pm="{$apm.paymentMethod}"  onclick="scShowCheckMark($(this))">
													<i class="icon--check" style="position: relative; bottom: 10px; color: green; display: none;"></i>
													<img src="{$apm.logoURL}" alt="{$apm.paymentMethodDisplayName[0].message}" style="display: inline-block;" />
												</label>

												{if $apm.fields}
													<span class="filter-panel--icon"></span>

													<div class="filter-panel--content">
														<ul class="filter-panel--option-list">
															{if $apm.paymentMethod neq "cc_card"}
																{foreach $apm.fields as $fields}
																	<li class="filter-panel--option">
																		<input id="{$apm.paymentMethod}_{$fields.name}"
																			   name="{$apm.paymentMethod}[{$fields.name}]"
																			   type="{$fields.type}"
																			   {if $fields.regex}pattern="{$fields.regex}"{/if}
																			   placeholder="{if $fields.caption[0].message}{$fields.caption[0].message}{else}{$fields.name}{/if}"
																			   class="sc_fields" />

																		{if $fields.regex and $fields.validationmessage[0].message}
																			<i class="icon--question" onclick="$('#error_sc_{$fields.name}').toggle()" style="cursor: pointer;"></i>
																			<label class="apm_error help-block" id="error_sc_{$fields.name}" style="display: none;">{$fields.validationmessage[0].message}</label>
																		{/if}
																	</li>
																{/foreach}
															{else}
																<li class="filter-panel--option">
																	<input type="text" id="sc_card_holder_name" name="{$apm.paymentMethod}[cardHolderName]" placeholder="Card holder name" class="sc_fields" />
																</li>
																
																<li class="filter-panel--option">
																	<div id="sc_card_number" class="sc_fields"></div>
																</li>
																
																<li class="filter-panel--option">
																	<div id="sc_card_expiry" class="sc_fields"></div>
																</li>
																
																<li class="filter-panel--option">
																	<div id="sc_card_cvc" class="sc_fields"></div>
																</li>
															{/if}
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
						// styles for the fields
						var fieldsStyle = {
							base: {
								fontSize: 15
								,fontFamily: 'sans-serif'
								,color: '#43454b'
								,fontSmoothing: 'antialiased'
								,'::placeholder': {
									color: '#8798a9'
								}
							}
						};
						
						var elementClasses = {
							focus: 'focus',
							empty: 'empty',
							invalid: 'invalid',
						};
						
						var sfc                         = null;
						var scFields                    = null;
						var sfcFirstField               = null;
						var scCard                      = null;
						var cardNumber                  = null;
						var cardExpiry                  = null;
						var cardCvc                     = null;
						var scData                      = {};
						
						
						// show checkmark on selected payment method
						function scShowCheckMark(_this) {
							_this.closest('.panel--body').find('i.icon--check').hide();
							_this.find('i.icon--check').show();
							
							$('#sc_payment_method').val(_this.attr('data-pm'));
							
						}

						// check if payment method is selected and its fields are filled
						function scVerifyFields() {
							if($('#sc_payment_method').val() == "") {
								
							}
						}
						
						scData.merchantSiteId   = '{$merchantSiteId}';
						scData.merchantId       = '{$merchantId}';
						scData.sessionToken     = '{$session_token}';

						{if $testMode eq 'yes'}
							scData.env = 'test';
						{/if}
							
						try {
							sfc = SafeCharge(scData);

							// prepare fields
							scFields = sfc.fields({
								locale: '{$langCode}'
							});
							
							cardNumber = sfcFirstField = scFields.create('ccNumber', {
								classes: elementClasses
								,style: fieldsStyle
							});
							cardNumber.attach('#sc_card_number');

							cardExpiry = scFields.create('ccExpiration', {
								classes: elementClasses
								,style: fieldsStyle
							});
							cardExpiry.attach('#sc_card_expiry');

							cardCvc = scFields.create('ccCvc', {
								classes: elementClasses
								,style: fieldsStyle
							});
							cardCvc.attach('#sc_card_cvc');
						}
						catch(exception) {
							alert('{s name="frontend/safecharge_payment/ex_alert"}Unexpected error, you can not pay with card at the moment!{/s}')
							console.error('Exception ' + exception);
						}
					</script>
                </form>
            </div>
        </div>
    </div>
{/block}
