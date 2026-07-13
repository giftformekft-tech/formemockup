(function (blocks, element, components, blockEditor) {
    'use strict';
    var el = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var useBlockProps = blockEditor.useBlockProps;
    var TextControl = components.TextControl;
    var CheckboxControl = components.CheckboxControl;
    var PanelBody = components.PanelBody;
    var recipients = (window.MG_GIFT_BLOCK && window.MG_GIFT_BLOCK.recipients) || [];

    blocks.registerBlockType('mockup-generator/gift-finder', {
        title: 'Ajándékkereső',
        icon: 'heart',
        category: 'widgets',
        description: 'Mozgatható főoldali blokk a Forme ajándékkeresőhöz.',
        supports: { align: ['wide', 'full'] },
        attributes: {
            title: { type: 'string', default: 'Találd meg a tökéletes ajándékot' },
            intro: { type: 'string', default: 'Először válaszd ki, kinek keresel ajándékot, és máris mutatjuk a hozzá illő alkalmakat.' },
            buttonLabel: { type: 'string', default: 'Tovább az alkalomhoz' },
            categoryIds: { type: 'array', default: [], items: { type: 'number' } },
            recipientIds: { type: 'array', default: [], items: { type: 'number' } }
        },
        edit: function (props) {
            var a = props.attributes;
            var selected = a.recipientIds || [];
            var controls = el(InspectorControls, {},
                el(PanelBody, { title: 'Szövegek', initialOpen: true },
                    el(TextControl, { label: 'Főcím', value: a.title, onChange: function (v) { props.setAttributes({ title: v }); } }),
                    el(TextControl, { label: 'Bevezető', value: a.intro, onChange: function (v) { props.setAttributes({ intro: v }); } }),
                    el(TextControl, { label: 'Gomb felirata', value: a.buttonLabel, onChange: function (v) { props.setAttributes({ buttonLabel: v }); } })
                ),
                el(PanelBody, { title: 'Megjelenő címzettek', initialOpen: true },
                    recipients.length ? recipients.map(function (recipient) {
                        return el(CheckboxControl, {
                            key: recipient.id, label: recipient.name, checked: selected.indexOf(recipient.id) !== -1,
                            onChange: function (checked) {
                                var next = checked ? selected.concat([recipient.id]) : selected.filter(function (id) { return id !== recipient.id; });
                                props.setAttributes({ recipientIds: next });
                            }
                        });
                    }) : el('p', {}, 'Előbb állíts be címzetteket a Mockup Generator → Ajándékkereső oldalon.')
                )
            );
            return el('div', useBlockProps({ className: 'mg-gift-editor-preview' }), controls,
                el('span', { className: 'mg-gift-eyebrow' }, 'Ajándékötletek személyre szabva'),
                el('h2', {}, a.title), el('p', {}, a.intro),
                el('strong', {}, 'Kinek keresel ajándékot?'),
                el('div', { className: 'mg-gift-editor-cards' }, (selected.length ? recipients.filter(function (r) { return selected.indexOf(r.id) !== -1; }) : recipients).map(function (r) { return el('span', { key: r.id }, r.name); })),
                el('span', { className: 'mg-gift-primary-button' }, a.buttonLabel + ' →')
            );
        },
        save: function () { return null; }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor);
