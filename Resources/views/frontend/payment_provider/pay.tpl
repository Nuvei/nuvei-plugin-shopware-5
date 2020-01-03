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
		
		.sc_fields.focus {
			box-shadow: 0 0 0 transparent;
			outline: none;
			border-color: #d9400b;
			background: #fff;
			color: #5f7285;
		}
		
		.is-in { z-index: 999 !important; }
		
		.sfcModal-dialog {
			width: 50%;
			margin: 0 auto;
			margin-top: 10%;
		}
        
        .js--loading {
            width: 18px;
            width: 1.125rem;
            height: 18px;
            height: 1.125rem;
            border-radius: 100%;
            background-clip: padding-box;
            right: 6px;
            right: .375rem;
            top: 2px;
            top: .125rem;
            margin: 8px 5px 8px 5px;
            margin: .5rem .3125rem .5rem .3125rem;
            -webkit-animation: keyframe--spin 1s linear infinite;
            animation: keyframe--spin 1s linear infinite;
            border: 2px solid #dadae5;
                border-top-color: rgb(218, 218, 229);
                border-top-style: solid;
                border-top-width: 2px;
            border-top: 2px solid #4f4f71;
            display: none;
            position: absolute;
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
                <form method="post" id="sc_payment_form" action="{$apmPaymentURL}">
                    <input type="hidden" name="session_token" value="{$session_token}" />
                    
					<div id="apms_error_msg" class="alert is--error is--rounded" style="display: none;">
						<div class="alert--icon">
							<i class="icon--element icon--cross"></i>
						</div>
						
						<div class="alert--content">
							{s name="frontend/safecharge_payment/apms_alert"}Please, choose a payment method and fill its fields!{/s}
						</div>
					</div>
					
					<h3>{s name="frontend/safecharge_payment/h3"}Please, select your preffered way to pay{/s}:</h3>

					<div class="panel has--border is--rounded">
						{if $apms}
							<div class="panel panel--body">
								<div class="filter--facet-container" style="display: block;">
									<input type="hidden" id="sc_payment_method" name="sc_payment_method" value="" />
									<input type="hidden" id="sc_transaction_id" name="sc_transaction_id" value="" />
									
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
																			   class="sc_fields {$apm.paymentMethod}_fields" />

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

                    <button type="button" class="btn is--primary" preloader-button="true" onclick="window.location='{$cancelUrl}'">
                        Cancel order
                    </button>

                    <button type="button" class="btn is--secondary" preloader-button="true" onclick="scVerifyFields()">
                        Pay<i class="js--loading"></i>
                    </button>
                    
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
								$('#apms_error_msg').show();
								return;
							}
                            
							// pay with card - use WebSDK
							if($('#sc_payment_method').val() === 'cc_card') {
								$('.is--primary, .is--secondary').prop('disabled', true);
								$('.is--secondary').css('width', 76);
								$('.js--loading').show();
								
								// create payment with WebSDK
								sfc.createPayment({
									sessionToken    : "{$session_token}",
									merchantId      : "{$merchantId}",
									merchantSiteId  : "{$merchantSiteId}",
									currency        : "{$currency}",
									amount          : "{$amount}",
									cardHolderName  : document.getElementById('sc_card_holder_name').value,
									paymentOption   : sfcFirstField,
									webMasterId		: "{$webMasterId}"
								}, function(resp){
									console.log(resp);

									if(typeof resp.result != 'undefined') {
										if(resp.result == 'APPROVED' && resp.transactionId != 'undefined') {
											$('#sc_transaction_id').val(resp.transactionId);
											
											$('#sc_payment_form')
												.attr('action', '{$successURL}')
												.submit();
										
											return;
											console.log('after retun')
										}
										
										if(resp.result == 'DECLINED') {
											alert("{s name="frontend/safecharge_payment/payment_declined"}Your Payment was DECLINED. Please try another payment method!{/s}");
										}
										else {
											if(typeof resp.errorDescription != 'undefined' && resp.errorDescription != '') {
												alert(resp.errorDescription);
											}
											else if('undefined' != typeof resp.reason && '' != resp.reason) {
												alert(resp.reason);
											}
											else {
												alert("{s name="frontend/safecharge_payment/payment_error"}Error with your Payment. Check your credentiols and try again later!{/s}");
											}
										}
									}
									else {
										alert("{s name="frontend/safecharge_payment/payment_unex_error"}Unexpected error, please try again later!{/s}");
									}

									$('.is--primary, .is--secondary').prop('disabled', false);
									$('.is--secondary').css('width', 'auto');
									$('.js--loading').hide();
								});
								
								return;
							}
							
							// pay with APM
							// check for payment method fields
							var pmFields = document.getElementsByClassName($('#sc_payment_method').val() + '_fields');
							
							for(var i in pmFields) {
								if(typeof pmFields[i].value != 'undefined' && pmFields[i].value == '') {
									$('#apms_error_msg').show();
									return;
								}
							}
                            
                            $('#sc_payment_form').submit();
						}
						console.log('apm');
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
							
							var scPaymentMethod = document.getElementById('sc_payment_method').value;
							// check for cache, may be payment method is selected
							if(scPaymentMethod != '') {
								// array with labels
								var lablesHolder = document.getElementsByTagName('label');
								var stopBreak = false;
								
								try {
									for(var i in lablesHolder) {
										if(
											typeof lablesHolder[i].getAttribute('data-pm') != 'undefined'
											&& lablesHolder[i].getAttribute('data-pm') === scPaymentMethod
										) {
											// array with label childs
											var labelChilds = lablesHolder[i].childNodes;

											for(var j in labelChilds) {
												if(
													typeof labelChilds[j].className != 'undefined'
													&& 'icon--check' === labelChilds[j].className
												) {
													labelChilds[j].style.display = 'inline-block';
													stopBreak = true;
													break;
												}
											}
										}

										if(stopBreak) {
											break;
										}
									}
								}
								catch(exception) {}
							}
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
