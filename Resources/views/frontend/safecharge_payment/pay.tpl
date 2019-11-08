{extends file="frontend/index/index.tpl"}

{block name='frontend_index_shop_navigation'}{/block}
{block name='frontend_index_content_left'}{/block}

{block name='frontend_index_content'}
	<style type="text/css">
		.sc_fields {
			width: 93%;
			margin-top: 5px;
		}
	</style>
	
    <div class="content listing--content">
        <div class="hero-unit category--teaser panel has--border is--rounded">
            <div class="hero--text panel--body is--wide">
                <form method="post" id="sc_payment_form">
                    {$formInputs}
					
					<h3>Please, select your preffered way to pay:</h3>

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
																	<input type="text" id="sc_card_number" class="sc_fields" />
																</li>
																
																<li class="filter-panel--option">
																	<input type="text" id="sc_card_expiry" class="sc_fields" />
																</li>
																
																<li class="filter-panel--option">
																	<input type="text" id="sc_card_cvc" class="sc_fields" />
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
								fontSize: 15.5
								,fontFamily: 'sans-serif'
								,color: '#43454b'
								,fontSmoothing: 'antialiased'
								,'::placeholder': {
									color: '#52545A'
								}
							}
						};
						
						// show checkmark on selected payment method
						function scShowCheckMark(_this) {
							_this.closest('.panel--body').find('i.icon--check').hide();
							_this.find('i.icon--check').show();
						}

						// check if payment method is selected and its fields are filled
						function scVerifyFields() {

						}
					</script>
                </form>
            </div>
        </div>
    </div>
{/block}
