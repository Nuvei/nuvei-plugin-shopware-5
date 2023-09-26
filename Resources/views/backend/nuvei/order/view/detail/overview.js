//{block name="backend/order/view/detail/overview"}
// {$smarty.block.parent}
Ext.define('Shopware.apps.Order.view.detail.Overview', {
//    override: 'Shopware.apps.Order.view.detail.Overview',
    extend: 'Shopware.apps.Order.view.detail.Overview'
    ,alias:  'scOptions'
//    ,scOrderData: []
    
    ,initComponent: function () {
        var me = this;
        me.callParent();
        
        me.insert(2, me.createSCRefundsList());
        me.insert(3, me.createSCNotesList());
        me.insert(4, me.createSCEditContainer());
        me.insert(5, me.renderSCData());
    }
    
    ,scOrderActions: function(action, btnId, trId) {
        var me = this;
        
        if (!confirm('Are you sure you want to apply "' + action + '" action on this Order?')) {
            return;
        }
        
        Ext.ComponentQuery.query('#scPanelLoadingImg')[0].show();
        Ext.ComponentQuery.query('#'+btnId)[0].hide();
        
        var reqData = {
            scAction: action
            ,orderId: me.record.get('id')
            ,trId: trId
            ,appendCSRFToken: true
        };
        
        if(action == 'refund') {
            reqData.refundAmount = document.getElementsByName('scRefundAmount')[0].value
        }

        Ext.Ajax.request({
            url: '{url controller=NuveiOrderEdit action="process"}',
            method: 'POST',
            params: reqData,
            success: function(response) {
                var resp = Ext.decode(response.responseText);
                
                console.log('scOrderActions', resp);
                
                // ERROR
                if (!resp.hasOwnProperty('transactionStatus') 
                    || resp.transactionStatus != 'APPROVED'
                ) {
                    Ext.ComponentQuery.query('#scPanelLoadingImg')[0].hide();

                    if(typeof resp.reason != 'undefined' && resp.reason != '') {
                        alert(resp.data.reason);
                        return;
                    }
                    if(typeof resp.message != 'undefined' && resp.message != '') {
                        alert(resp.msg);
                        return;
                    }

                    alert('Unexpected error.');
                    return;
                }
                
                // SUCCESS
                Ext.ComponentQuery.query('#scPanelLoadingImg')[0].hide();
                alert('Success! Please, close Order Details window, and open it again!');
                return;
            }
        });
    }
    
    // create a list with the Refunds, if there are refunds
    ,createSCRefundsList: function() {
        var scRefundsContainer = Ext.create('Ext.form.Panel', {
            title: 'Nuvei Refunds',
            titleAlign: 'left',
            bodyPadding: 10,
            layout: 'anchor',
            itemId: 'scRefundsContainer',
            margin: '10 0',
            items: [
                {
                    xtype: 'image',
                    src: '/themes/Backend/ExtJs/backend/_resources/resources/themes/images/default/shared/loading-balls.gif',
                    itemId: 'scRefundsLoadingImg'
                }
            ]
        });
        
        return scRefundsContainer;
    }
    
    ,createSCNotesList: function() {
        var scNotesContainer = Ext.create('Ext.form.Panel', {
            title: 'Nuvei Notes',
            titleAlign: 'left',
            bodyPadding: 10,
            layout: 'anchor',
            itemId: 'scNotesContainer',
            margin: '10 0',
            items: [
                {
                    xtype: 'image',
                    src: '/themes/Backend/ExtJs/backend/_resources/resources/themes/images/default/shared/loading-balls.gif',
                    itemId: 'scNotesLoadingImg'
                }
            ]
        });
        
        return scNotesContainer;
    }
    
    // create the container
    ,createSCEditContainer: function() {
        var scFinalContainer = Ext.create('Ext.form.Panel', {
            title: 'Nuvei Options',
            titleAlign: 'left',
            bodyPadding: 10,
            layout: 'anchor',
            itemId: 'scFinalContainer',
            margin: '10 0',
            items: [{
                xtype: 'image',
                src: '/themes/Backend/ExtJs/backend/_resources/resources/themes/images/default/shared/loading-balls.gif',
                itemId: 'scPanelLoadingImg'
            }]
        });

        return scFinalContainer;
    }
    
    ,renderSCData: function() {
        var me = this;
        var scFinalItems = [];
        
        // get order attributes
        Ext.Ajax.request({
            url: '{url controller=NuveiOrderEdit action="getSCOrderData"}',
            method: 'POST',
            async: true,
            params: { orderId: me.record.get('id') },
            success: function(response) {
                var resp = Ext.decode(response.responseText);
                
                // error - no status
                if (!resp.hasOwnProperty('status')) {
                    Ext.ComponentQuery.query('#scFinalContainer')[0]
                        .remove(Ext.ComponentQuery.query('#scPanelLoadingImg')[0]);
                
                    Ext.ComponentQuery.query('#scRefundsContainer')[0]
                        .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);
                    
                    console.error('Nuvei Error - missing response status.')
                    return;
                }
                
                // error, status is not success
                if (resp.status != 'success') {
                    alert(resp.msg);
                    
                    Ext.ComponentQuery.query('#scFinalContainer')[0]
                        .remove(Ext.ComponentQuery.query('#scPanelLoadingImg')[0]);
                
                    Ext.ComponentQuery.query('#scRefundsContainer')[0]
                        .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);
                    
                    return;
                }
                
                // ON SUCCESS
                // enable Refund button
                if(typeof resp.scEnableRefund != 'undefined' && resp.scEnableRefund > 0) {
                    // refund area
                    var scRefundFieldTitle = Ext.create('Ext.form.field.Number', {
                        autoScroll: true
                        ,style: {
                            marginLeft: '10px',
                            marginTop: '10px',
                            marginBottom:'10px'
                        }
                        ,fieldLabel: 'Refund Amount:'
                        ,readOnly: false
                        ,minValue: 0.01
                        ,value: 1
                        ,decimalPrecision: 2
                        ,name: 'scRefundAmount'
                        ,submitLocaleSeparator: false
                    });

//                    var scManualRefundBtn = Ext.create('Ext.Button', {
//                        text: 'Refund Manually'
//                        ,cls: 'primary'
//                        ,style: { marginTop: '10px' }
//                        ,itemId: 'SCManualRefundBtn'
//                        ,handler: function() {
//                            me.scOrderActions('manualRefund', 'SCManualRefundBtn')
//                        }
//                    });

                    var scRefundBtn = Ext.create('Ext.Button', {
                        text: 'Refund via Nuvei'
                        ,cls: 'primary'
                        ,style: { marginTop: '10px' }
                        ,itemId: 'SCRefundBtn'
                        ,handler: function() {
                            me.scOrderActions('refund', 'SCRefundBtn', resp.scEnableRefund)
                        }
                    });

                    var scRefundArea = Ext.create('Ext.form.FieldSet', {
                        title: 'Nuvei Refund',
                        layout: 'hbox',
                        labelWidth: 75,
//                        items: [scRefundFieldTitle, scManualRefundBtn, scRefundBtn]
                        items: [scRefundFieldTitle, scRefundBtn]
                    });
                    // refund area END

                    scFinalItems.push(scRefundArea);
                }
                // /enable Refund button

                var scOtherOptionsArea = Ext.create('Ext.form.FieldSet', {
                    title: 'Nuvei other options',
                    layout: 'hbox',
                    labelWidth: 75,
                    items: []
                });

                // enable Void
                if(resp.hasOwnProperty('scEnableVoid') && resp.scEnableVoid > 0) {
                    // other options area
                    var scVoidBtn = Ext.create('Ext.Button', {
                        text: 'Void'
                        ,cls: 'primary'
                        ,style: { marginTop: '10px' }
                        ,itemId: 'SCVoidBtn'
                        ,handler: function() {
                            me.scOrderActions('void', 'SCVoidBtn', resp.scEnableVoid)
                        } 
                    });

                    scOtherOptionsArea.add({
                        bodyBorder: false,
                        border:false,
                        items: scVoidBtn
                    });
                }
                // /enable Void

                // enable Settle
                if(typeof resp.scEnableSettle != 'undefined' && resp.scEnableSettle > 0) {
                    var scSettleBtn = Ext.create('Ext.Button', {
                        text: 'Settle'
                        ,html: ''
                        ,cls: 'primary'
                        ,style: { marginTop: '10px' }
                        ,itemId: 'SCSettleBtn'
                        ,handler: function() {
                            me.scOrderActions('settle', 'SCSettleBtn', resp.scEnableSettle)
                        } 
                    });

                    scOtherOptionsArea.add({
                        bodyBorder: false,
                        border:false,
                        items: scSettleBtn
                    });
                }
                // enable Settle END

                if(scOtherOptionsArea.items.length > 0) {
                    scFinalItems.push(scOtherOptionsArea);
                }

//                    Ext.ComponentQuery.query('#scFinalContainer')[0]
//                        .remove(Ext.ComponentQuery.query('#scPanelLoadingImg')[0]);

                Ext.ComponentQuery.query('#scPanelLoadingImg')[0].hide();

                Ext.ComponentQuery.query('#scFinalContainer')[0].add({
                    bodyBorder: false,
                    border:false,
                    items: scFinalItems
               });

                // Show Refunds
                if (typeof resp.refunds != 'undefined' && Object.keys(resp.refunds).length > 0) {
                    // set table headers
                    var rows = [
                        { html: '<b>Date</b>', border: 0 }
                        ,{ html: '<b>Request ID</b>', border: 0 }
                        ,{ html: '<b>Transaction ID</b>', border: 0 }
                        ,{ html: '<b>Amount</b>' ,border: 0 }
                        ,{ html: '' ,border: 0 }

                    ];

                    // create Refunds table rows
                    for(var trId in resp.refunds) {
                        rows.push(
                            {
                                html: resp.refunds[trId].responseTimeStamp
                                ,border: 0
                            }
                            ,{
                                html: resp.refunds[trId].clientRequestId
                                ,border: 0
                            }
                            ,{
                                html: trId
                                ,border: 0
                            }
                            ,{
                                html: '-' + Number(resp.refunds[trId].totalAmount).toFixed(2)
                                ,border: 0 
                                ,style: {
                                    textAlign: "right"
                                }
                            }
                            ,{
                                html: '<div class="x-btn primary x-btn-default-small x-noicon x-btn-noicon x-btn-default-small-noicon" style="margin:10px 0px 0px 0px;border-width:1px 1px 1px 1px;" id="button-1686"><em><button type="button" class="x-btn-center" hidefocus="true" role="button" autocomplete="off" data-action="openCustomer" style="height: 24px;"><span class="x-btn-inner" style="">&times;</span></button></em></div>'
                                ,border: 0 
                                ,style: {
                                    textAlign: "right"
                                }
                            }
                        );
                    }

                    var cont = Ext.create('Ext.container.Container', {
                        layout: {
                            type: 'table'
                            ,columns: 5
                            ,tdAttrs: { style: 'padding: 10px;' }
                        }
                        ,items: rows
                    })

                    Ext.ComponentQuery.query('#scRefundsContainer')[0]
                        .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);

                    Ext.ComponentQuery.query('#scRefundsContainer')[0].add({
                        bodyBorder: false,
                        border:false,
                        items: cont
                    });
                }
                else {
                    Ext.ComponentQuery.query('#scRefundsContainer')[0]
                        .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);
                }
                // /Show Refunds
                
                // Show Notes
                if (resp.hasOwnProperty('notes') && Object.keys(resp.notes).length > 0) {
                    // set table headers
                    var rows = [
                        { html: '<b>Date</b>', border: 0 }
                        ,{ html: '<b>Comment</b>', border: 0 }
                    ];
                    
                    // create Notes table rows
                    for(var trId in resp.notes) {
                        rows.push(
                            {
                                html: resp.notes[trId].date
                                ,border: 0
                            }
                            ,{
                                html: resp.notes[trId].comment
                                ,border: 0
                            }
                        );
                    }
                    
                    var cont = Ext.create('Ext.container.Container', {
                        layout: {
                            type: 'table'
                            ,columns: 2
                            ,tdAttrs: { style: 'padding: 10px;' }
                        }
                        ,items: rows
                    });
                    
                    Ext.ComponentQuery.query('#scNotesContainer')[0]
                        .remove(Ext.ComponentQuery.query('#scNotesLoadingImg')[0]);

                    Ext.ComponentQuery.query('#scNotesContainer')[0].add({
                        bodyBorder: false,
                        border:false,
                        items: cont
                    });
                }
                else {
                    Ext.ComponentQuery.query('#scNotesContainer')[0]
                        .remove(Ext.ComponentQuery.query('#scNotesLoadingImg')[0]);
                }
                // /Show Notes
            },
            error: function() {
                Ext.ComponentQuery.query('#scFinalContainer')[0]
                    .remove(Ext.ComponentQuery.query('#scPanelLoadingImg')[0]);

                Ext.ComponentQuery.query('#scRefundsContainer')[0]
                    .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);
            }
        });
        // /get order attributes
    }
});
//{/block}