(function (blocks, element, components, blockEditor) {
    'use strict';
    var el = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var useBlockProps = blockEditor.useBlockProps;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var CheckboxControl = components.CheckboxControl;
    var Button = components.Button;
    var PanelBody = components.PanelBody;
    var MediaUpload = blockEditor.MediaUpload;
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

    blocks.registerBlockType('mockup-generator/trust-section', {
        title: 'Forme – Bizalomépítő rész',
        icon: 'groups',
        category: 'widgets',
        description: 'Saját gyártást, gépparkot, tapasztalatot és valódi csapatfotókat bemutató főoldali blokk.',
        supports: { align: ['wide', 'full'] },
        attributes: {
            eyebrow: { type: 'string', default: 'A termék mögött valódi műhely áll' },
            title: { type: 'string', default: 'Nem továbbadjuk. Mi készítjük.' },
            intro: { type: 'string', default: 'A Forme ajándékok saját gépparkkal, saját műhelyben, Debrecen közelében készülnek – olyan emberek kezében, akik nap mint nap ezzel foglalkoznak.' },
            familyText: { type: 'string', default: 'Családi magyar vállalkozás' },
            locationText: { type: 'string', default: 'Saját gyártás Debrecen közelében' },
            experienceText: { type: 'string', default: 'Több év gyakorlati tapasztalat' },
            printingText: { type: 'string', default: 'Tartós DTG- és DTF-nyomtatás' },
            speedText: { type: 'string', default: 'Gyors, helyben végzett elkészítés' },
            reviewsText: { type: 'string', default: 'Valós vásárlói értékelések' },
            workshopImageId: { type: 'number', default: 0 },
            workshopImageUrl: { type: 'string', default: '' },
            workshopImageAlt: { type: 'string', default: 'A Forme saját műhelye és gépparkja' },
            workshopCaption: { type: 'string', default: 'Saját műhelyünk és gépparkunk' },
            teamImageId: { type: 'number', default: 0 },
            teamImageUrl: { type: 'string', default: '' },
            teamImageAlt: { type: 'string', default: 'A Forme csapata' },
            teamCaption: { type: 'string', default: 'A csapat, aki elkészíti a rendeléseket' }
        },
        edit: function (props) {
            var a = props.attributes;
            var factFields = [
                ['familyText', '1. vállalkozás'], ['locationText', '2. gyártási hely'],
                ['experienceText', '3. tapasztalat'], ['printingText', '4. nyomtatás'],
                ['speedText', '5. elkészítés'], ['reviewsText', '6. értékelések']
            ];

            function update(key, value) {
                var next = {};
                next[key] = value;
                props.setAttributes(next);
            }

            function mediaPanel(title, idKey, urlKey, altKey, captionKey) {
                return el(PanelBody, { title: title, initialOpen: false },
                    a[urlKey] ? el('img', { src: a[urlKey], alt: '', style: { width: '100%', marginBottom: '12px' } }) : null,
                    el(MediaUpload, {
                        allowedTypes: ['image'], value: a[idKey],
                        onSelect: function (media) {
                            var next = {};
                            next[idKey] = media.id || 0;
                            next[urlKey] = media.url || '';
                            next[altKey] = media.alt || a[altKey];
                            props.setAttributes(next);
                        },
                        render: function (mediaProps) {
                            return el(Button, { variant: 'secondary', onClick: mediaProps.open }, a[idKey] ? 'Fotó cseréje' : 'Fotó kiválasztása');
                        }
                    }),
                    a[idKey] ? el(Button, { variant: 'link', isDestructive: true, onClick: function () { var next = {}; next[idKey] = 0; next[urlKey] = ''; props.setAttributes(next); } }, 'Fotó eltávolítása') : null,
                    el(TextControl, { label: 'Képaláírás', value: a[captionKey], onChange: function (v) { update(captionKey, v); } }),
                    el(TextControl, { label: 'Kép helyettesítő szövege', value: a[altKey], onChange: function (v) { update(altKey, v); } })
                );
            }

            var controls = el(InspectorControls, {},
                el(PanelBody, { title: 'Cím és bevezető', initialOpen: true },
                    el(TextControl, { label: 'Felső kis cím', value: a.eyebrow, onChange: function (v) { update('eyebrow', v); } }),
                    el(TextControl, { label: 'Főcím', value: a.title, onChange: function (v) { update('title', v); } }),
                    el(TextareaControl, { label: 'Bevezető', value: a.intro, onChange: function (v) { update('intro', v); } })
                ),
                el(PanelBody, { title: 'Konkrét előnyök', initialOpen: true }, factFields.map(function (field) {
                    return el(TextControl, { key: field[0], label: field[1], value: a[field[0]], onChange: function (v) { update(field[0], v); } });
                })),
                mediaPanel('Műhely és géppark fotója', 'workshopImageId', 'workshopImageUrl', 'workshopImageAlt', 'workshopCaption'),
                mediaPanel('Csapatfotó', 'teamImageId', 'teamImageUrl', 'teamImageAlt', 'teamCaption')
            );

            return el('div', useBlockProps({ className: 'mg-trust-editor-preview' }), controls,
                el('div', { className: 'mg-trust-section__heading' },
                    el('span', { className: 'mg-gift-eyebrow' }, a.eyebrow),
                    el('h2', {}, a.title), el('p', {}, a.intro)
                ),
                el('div', { className: 'mg-trust-section__body' },
                    el('ul', { className: 'mg-trust-section__facts' }, factFields.map(function (field, index) {
                        return el('li', { key: field[0] }, el('span', {}, ('0' + (index + 1)).slice(-2)), el('strong', {}, a[field[0]]));
                    })),
                    (a.workshopImageUrl || a.teamImageUrl) ? el('div', { className: 'mg-trust-section__media' },
                        a.workshopImageUrl ? el('figure', { className: 'mg-trust-section__photo mg-trust-section__photo--workshop' }, el('img', { src: a.workshopImageUrl, alt: '' }), el('figcaption', {}, a.workshopCaption)) : null,
                        a.teamImageUrl ? el('figure', { className: 'mg-trust-section__photo mg-trust-section__photo--team' }, el('img', { src: a.teamImageUrl, alt: '' }), el('figcaption', {}, a.teamCaption)) : null
                    ) : el('div', { className: 'mg-trust-editor-placeholder' }, 'A jobb oldali beállításoknál tölts fel műhely- és csapatfotót.')
                )
            );
        },
        save: function () { return null; }
    });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor);
