(function (blocks, element, components, i18n) {
    const { __ } = i18n;
    const { registerBlockType } = blocks;
    const { Fragment } = element;
    const { PanelBody, TextControl, RangeControl, SelectControl, ToggleControl } = components;

    registerBlockType('mockup-generator/design-gallery', {
        title: __('Mintagalléria', 'mgdg'),
        description: __('Mutasd meg a design-t az összes terméktípus alapértelmezett színén.', 'mgdg'),
        icon: 'images-alt2',
        category: 'widgets',
        attributes: {
            title: {
                type: 'string',
                default: '',
            },
            maxItems: {
                type: 'number',
                default: 0,
            },
            layout: {
                type: 'string',
                default: 'grid',
            },
            showTitle: {
                type: 'boolean',
                default: true,
            },
        },
        edit: function (props) {
            const { attributes, setAttributes } = props;
            return (
                element.createElement(
                    Fragment,
                    null,
                    element.createElement(
                        'div',
                        { className: 'mg-design-gallery-editor-note' },
                        __('A blokk dinamikusan jelenik meg az oldalon. Mentés után a frontenden a legutóbbi mockup képek láthatók.', 'mgdg')
                    ),
                    element.createElement(
                        PanelBody,
                        { title: __('Beállítások', 'mgdg'), initialOpen: true },
                        element.createElement(TextControl, {
                            label: __('Cím', 'mgdg'),
                            value: attributes.title,
                            onChange: function (value) {
                                setAttributes({ title: value });
                            },
                            placeholder: __('Minta az összes terméken', 'mgdg'),
                        }),
                        element.createElement(ToggleControl, {
                            label: __('Cím megjelenítése', 'mgdg'),
                            checked: attributes.showTitle,
                            onChange: function (value) {
                                setAttributes({ showTitle: value });
                            },
                        }),
                        element.createElement(RangeControl, {
                            label: __('Maximális elemek száma', 'mgdg'),
                            value: attributes.maxItems,
                            onChange: function (value) {
                                setAttributes({ maxItems: value });
                            },
                            min: 0,
                            max: 12,
                            help: __('0 = az összes elérhető termék', 'mgdg'),
                        }),
                        element.createElement(SelectControl, {
                            label: __('Elrendezés', 'mgdg'),
                            value: attributes.layout,
                            options: [
                                { label: __('Rács', 'mgdg'), value: 'grid' },
                                { label: __('Lista', 'mgdg'), value: 'list' },
                            ],
                            onChange: function (value) {
                                setAttributes({ layout: value });
                            },
                        })
                    )
                )
            );
        },
        save: function () {
            return null;
        },
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.i18n);
