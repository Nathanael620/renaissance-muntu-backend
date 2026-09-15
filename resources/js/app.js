import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.tsx';

const root = document.createElement('div');
root.id = 'root';
document.body.replaceChildren(root);

createRoot(root).render(
	React.createElement(React.StrictMode, null, React.createElement(App)),
);
