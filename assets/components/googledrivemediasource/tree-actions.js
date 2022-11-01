var GoogleDriveMS = function(config) {
    config = config || {};
    GoogleDriveMS.superclass.constructor.call(this,config);
};
Ext.extend(GoogleDriveMS,Ext.Component, {
    openInDrive: function () {
        const node = this.cm.activeNode;
        if (node.attributes.drive?.webViewLink) {
            window.open(node.attributes.drive.webViewLink)
        }
    },

    download: function(mimeType) {
        const node = this.cm.activeNode;

        let url = node.attributes.url;
        url += url.indexOf('?') === -1 ? '?' : '&';
        url += 'f=' + encodeURIComponent(mimeType);

        window.open(url);
    },

    downloadAsPDF: function () {
        GoogleDriveMS.download.bind(this)('application/pdf');
    },

    downloadAsWord: function () {
        GoogleDriveMS.download.bind(this)('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    },

    downloadAsExcel: function () {
        GoogleDriveMS.download.bind(this)('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    },

    downloadAsPowerpoint: function () {
        GoogleDriveMS.download.bind(this)('application/vnd.openxmlformats-officedocument.presentationml.presentation');
    },
});

Ext.reg('googledrivems',GoogleDriveMS);
GoogleDriveMS = new GoogleDriveMS();