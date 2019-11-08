//{block name="backend/order/view/detail/overview"}
// {$smarty.block.parent}
Ext.define('Shopware.apps.Order.view.detail.Overview', {
//    override: 'Shopware.apps.Order.view.detail.Overview',
    extend: 'Shopware.apps.Order.view.detail.Overview'
    ,alias:  'scOptions'
    
    ,scOrderData: []
    
    ,initComponent: function () {
        var me = this;
        me.callParent();
        
        me.insert(2, me.createSCRefundsList());
        me.insert(3, me.createSCNotesList());
        me.insert(4, me.createSCEditContainer());
        me.insert(5, me.renderSCData());
    }
    
    ,scOrderActions: function(action, btnId, refId, ordId) {
        var me = this;
        
        
        console.log(btnId)
        
    //    Ext.ComponentQuery.query('#'+btnId)[0].hide();
        console.log(action)
        
        var reqData = {
            scAction: action
            ,appendCSRFToken: true
        };
        
        if(action == 'refund' || action == 'manualRefund') {
            Ext.ComponentQuery.query('#scPanelLoadingImg')[0].show();
            
            reqData.refundAmount = document.getElementsByName('scRefundAmount')[0].value;
            reqData.orderId = me.record.get('id');
        }
        else if(action == 'deleteRefund') {
            Ext.ComponentQuery.query('#scRefundsLoadingImg')[0].show();
            
            reqData.refundId = refId;
            reqData.orderId = ordId;
        }
return;
        Ext.Ajax.request({
            url: '{url controller=SafeChargeOrderEdit action="process"}',
            method: 'POST',
            params: reqData,
            success: function(response) {
                var resp = Ext.decode(response.responseText);
                if (resp.status == 'success' || resp.status == 1) {
                    Ext.ComponentQuery.query('#scPanelLoadingImg')[0].hide();
                    
                    if(action == 'deleteRefund') {
                        // hide the refund row
                        var parId = Ext.ComponentQuery.query('#'+btnId)[0].up()['id'];
                        document.getElementById(parId).closest('tr').style.display = 'none'
                    }
                    
                    alert('Succes! Please, close Order Details window, and open it again!');
                }
                else {
                    Ext.ComponentQuery.query('#scPanelLoadingImg')[0].hide();
                    
                    if(
                        typeof resp.data != 'undefined'
                        && typeof resp.data.reason != 'undefined'
                        && resp.data.reason != ''
                    ) {
                        alert(resp.data.reason);
                    }
                    else if(typeof resp.msg != 'undefined' && resp.msg != '') {
                        alert(resp.msg);
                    }
                    else {
                        alert('ERROR');
                    }
                }
            }
        });
    }
    
    // create a list with the Refunds, if there are refunds
    ,createSCRefundsList: function() {
        var scRefundsContainer = Ext.create('Ext.form.Panel', {
            title: 'SafeCharge Refunds',
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
            title: 'SafeCharge Notes',
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
            title: 'SafeCharge Options',
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
            url: '{url controller=SafeChargeOrderEdit action="getSCOrderData"}',
            method: 'POST',
            async: true,
            params: { orderId: me.record.get('id') },
            success: function(response) {
                var resp = Ext.decode(response.responseText);
                
                if (resp.status == 'success') {
                    if(
                        typeof resp.scOrderData.relatedTransactionId == 'undefined'
                        || resp.scOrderData.relatedTransactionId == ''
                        || typeof resp.scOrderData.respTransactionType == 'undefined'
                        || resp.scOrderData.respTransactionType == ''
                    ) {
                        alert('The Order miss TransactionID or Transaction Type.');
                    
                    //    Ext.ComponentQuery.query('#scFinalContainer')[0]
                    //        .remove(Ext.ComponentQuery.query('#scPanelLoadingImg')[0]);
                        Ext.ComponentQuery.query('#scPanelLoadingImg')[0].hide();
                        
                    //    Ext.ComponentQuery.query('#scRefundsContainer')[0]
                    //        .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);
                            Ext.ComponentQuery.query('#scRefundsLoadingImg')[0].hide();

                        return;
                    }
                    
                    // enable Refund button
                    if(typeof resp.scEnableRefund != 'undefined' && resp.scEnableRefund) {
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

                        var scManualRefundBtn = Ext.create('Ext.Button', {
                            text: 'Refund Manually'
                            ,cls: 'primary'
                            ,style: { marginTop: '10px' }
                            ,itemId: 'SCManualRefundBtn'
                            ,handler: function() {
                                me.scOrderActions('manualRefund', 'SCManualRefundBtn')
                            }
                        });

                        var scRefundBtn = Ext.create('Ext.Button', {
                            text: 'Refund via SafeCharge'
                            ,cls: 'primary'
                            ,style: { marginTop: '10px' }
                            ,itemId: 'SCRefundBtn'
                            ,handler: function() {
                                me.scOrderActions('refund', 'SCRefundBtn')
                            }
                        });
                        
                        var scRefundArea = Ext.create('Ext.form.FieldSet', {
                            title: 'SafeCharge Refund',
                            layout: 'hbox',
                            labelWidth: 75,
                            items: [scRefundFieldTitle, scManualRefundBtn, scRefundBtn]
                        });
                        // refund area END
                        
                        scFinalItems.push(scRefundArea);
                    }
                    // enable Refund button END
                    
                    var scOtherOptionsArea = Ext.create('Ext.form.FieldSet', {
                        title: 'SafeCharge other options',
                        layout: 'hbox',
                        labelWidth: 75,
                        items: []
                    });
                    
                    // enable Void
                    if(typeof resp.scEnableVoid != 'undefined' && resp.scEnableVoid) {
                        // other options area
                        var scVoidBtn = Ext.create('Ext.Button', {
                            text: 'Void'
                            ,cls: 'primary'
                            ,style: { marginTop: '10px' }
                            ,itemId: 'SCVoidBtn'
                            ,handler: function() {
                                me.scOrderActions('void', 'SCVoidBtn')
                            } 
                        });
                        
                        scOtherOptionsArea.add({
                            bodyBorder: false,
                            border:false,
                            items: scVoidBtn
                        });
                    }
                    // enable Void END
                    
                    // enable Settle
                    if(
                        typeof resp.scOrderData.respTransactionType != 'undefined'
                        && resp.scOrderData.respTransactionType == 'Auth'
                    ) {
                        var scSettleBtn = Ext.create('Ext.Button', {
                            text: 'Settle'
                            ,html: ''
                            ,cls: 'primary'
                            ,style: { marginTop: '10px' }
                            ,itemId: 'SCSettleBtn'
                            ,handler: function() {
                                me.scOrderActions('settle', 'SCSettleBtn')
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
                    
                    Ext.ComponentQuery.query('#scPanelLoadingImg')[0].hide();
                    
                    Ext.ComponentQuery.query('#scFinalContainer')[0].add({
                        bodyBorder: false,
                        border:false,
                        items: scFinalItems
                   });
                   
                   // Show Refunds
                   if(
                        typeof resp.scOrderData.refunds != 'undefined'
                        && resp.scOrderData.refunds.length > 0
                    ) {
                        // set table headers
                        var rows = [
                            { html: '<b>Date</b>', border: 0 }
                            ,{ html: '<b>Request ID</b>', border: 0 }
                            ,{ html: '<b>Transaction ID</b>', border: 0 }
                            ,{ html: '<b>Amount</b>' ,border: 0 }
                            ,{ html: '' ,border: 0 }
                            
                        ];
                        
                        // create Refunds table rows
                        for(var rec in resp.scOrderData.refunds) {
                            rows.push(
                                {
                                    html: resp.scOrderData.refunds[rec].date
                                    ,border: 0
                                }
                                ,{
                                    html: resp.scOrderData.refunds[rec].client_unique_id
                                    ,border: 0
                                }
                                ,{
                                    html: resp.scOrderData.refunds[rec].transactionId
                                    ,border: 0
                                }
                                ,{
                                    html: '-' + Number(resp.scOrderData.refunds[rec].amount).toFixed(2)
                                    ,border: 0 
                                    ,style: {
                                        textAlign: "right"
                                    }
                                }
                                ,{
                                    border: 0 
                                    ,style: {
                                        textAlign: "right"
                                    }
                                    ,items: [
                                        Ext.create('Ext.Button', {
                                            text: '<b>&times;</b>'
                                            ,cls: 'small secondary'
                                            ,style: {}
                                            ,itemId: 'SCDelRefundBtn' + resp.scOrderData.id
                                            ,handler: function() {
                                                me.scOrderActions(
                                                    'deleteRefund', 'SCDelRefundBtn' + resp.scOrderData.refunds[rec].id,
                                                    resp.scOrderData.refunds[rec].id, resp.scOrderData.refunds[rec].order_id
                                                )
                                            }
                                        })
                                    ]
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
                        
                    //    Ext.ComponentQuery.query('#scRefundsContainer')[0]
                    //        .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);
                        Ext.ComponentQuery.query('#scRefundsLoadingImg')[0].hide();
                        
                        Ext.ComponentQuery.query('#scRefundsContainer')[0].add({
                            bodyBorder: false,
                            border:false,
                            items: cont
                        });
                    }
                    else {
//                        Ext.ComponentQuery.query('#scRefundsContainer')[0]
//                            .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);
                        Ext.ComponentQuery.query('#scRefundsLoadingImg')[0].hide();
                    }
                    // Show Refunds
                }
                // response not success
                else {
                //    alert(resp.msg);
                    
//                    Ext.ComponentQuery.query('#scFinalContainer')[0]
//                        .remove(Ext.ComponentQuery.query('#scPanelLoadingImg')[0]);
                
                        Ext.ComponentQuery.query('#scPanelLoadingImg')[0].hide();
                
//                    Ext.ComponentQuery.query('#scRefundsContainer')[0]
//                        .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);
                
                    Ext.ComponentQuery.query('#scRefundsLoadingImg')[0].hide();
                    
                    return;
                }
            },
            error: function() {
//                Ext.ComponentQuery.query('#scFinalContainer')[0]
//                    .remove(Ext.ComponentQuery.query('#scPanelLoadingImg')[0]);
                Ext.ComponentQuery.query('#scPanelLoadingImg')[0].hide();

//                Ext.ComponentQuery.query('#scRefundsContainer')[0]
//                    .remove(Ext.ComponentQuery.query('#scRefundsLoadingImg')[0]);
                Ext.ComponentQuery.query('#scRefundsLoadingImg')[0].hide();
            }
        });
        // get order attributes END
        
        // get Order Notes
        Ext.Ajax.request({
            url: '{url controller=SafeChargeOrderEdit action="getSCOrderNotes"}',
            method: 'POST',
            async: true,
            params: { orderId: me.record.get('id') },
            success: function(response) {
                var resp = Ext.decode(response.responseText);
                
                if (resp.status == 'success') {
                    // set table headers
                    var rows = [
                        { html: '<b>Date</b>', border: 0 }
                        ,{ html: '<b>Comment</b>', border: 0 }
                    ];
                    
                    // create Notes table rows
                    for(var rec in resp.notes) {
                        rows.push(
                            {
                                html: resp.notes[rec].date
                                ,border: 0
                            }
                            ,{
                                html: resp.notes[rec].comment
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
                    })

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
            },
            error: function() {
                Ext.ComponentQuery.query('#scNotesContainer')[0]
                    .remove(Ext.ComponentQuery.query('#scNotesLoadingImg')[0]);
            }
        });
        // get Order Notes END
    }
});
//{/block}