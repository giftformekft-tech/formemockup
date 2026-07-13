(function (blocks, element, components, blockEditor) {
    'use strict';
    var el = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var useBlockProps = blockEditor.useBlockProps;
    var TextControl = components.TextControl;
    var CheckboxControl = components.CheckboxControl;
    var PanelBody = components.PanelBody;
    var categories = (window.MG_GIFT_BLOCK && window.MG_GIFT_BLOCK.categories) || [];

    blocks.registerBlockType('mockup-generator/gift-finder', {
        title: 'Ajándékkereső',
        icon: 'heart',
        category: 'widgets',
        description: 'Mozgatható főoldali blokk a Forme ajándékkeresőhöz.',
        supports: { align: ['wide', 'full'] },
        attributes: {
            title: { type: 'string', default: 'Találd meg a tökéletes ajándékot' },
            intro: { type: 'string', default: 'Válassz egy kiindulópontot, és pár kérdés után személyre szabott ötleteket mutatunk.' },
            buttonLabel: { type: 'string', default: 'Ajándékkereső indítása' },
            categoryIds: { type: 'array', default: [], items: { type: 'number' } }
        },
        edit: function (props) {
            var a = props.attributes;
            var selected = a.categoryIds || [];
            var controls = el(InspectorControls, {},
                el(PanelBody, { title: 'Szövegek', initialOpen: true },
                    el(TextControl, { label: 'Főcím', value: a.title, onChange: function (v) { props.setAttributes({ title: v }); } }),
                    el(TextControl, { label: 'Bevezető', value: a.intro, onChange: function (v) { props.setAttributes({ intro: v }); } }),
                    el(TextControl, { label: 'Gomb felirata', value: a.buttonLabel, onChange: function (v) { props.setAttributes({ buttonLabel: v }); } })
                ),
                el(PanelBody, { title: 'Megjelenő kategóriák', initialOpen: true },
                    categories.length ? categories.map(function (cat) {
                        return el(CheckboxControl, {
                            key: cat.id, label: cat.name, checked: selected.indexOf(cat.id) !== -1,
                            onChange: function (checked) {
                                var next = checked ? selected.concat([cat.id]) : selected.filter(function (id) { return id !== cat.id; });
                                props.setAttributes({ categoryIds: next });
                            }
                        });
                    }) : el('p', {}, 'Előbb állíts be kategóriákat a Mockup Generator → Ajándékkereső oldalon.')
                )
            );
            return el('div', useBlockProps({ className: 'mg-gift-editor-preview' }), controls,
                el('span', { className: 'mg-gift-eyebrow' }, 'Ajándékötletek személyre szabva'),
                el('h2', {}, a.title), el('p', {}, a.intro),
                el('div', { className: 'mg-gift-editor-cards' }, (selected.length ? categories.filter(function (c) { return selected.indexOf(c.id) !== -1; }) : categories).map(function (c) { return el('span', { key: c.id }, c.name); })),
                el('span', { className: 'mg-gift-primary-button' }, a.buttonLabel + ' →')
            );
        },
        save: function () { return null; }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor);
